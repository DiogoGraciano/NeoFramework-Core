<?php

namespace NeoFramework\Core\Jobs\Drivers;

use Exception;
use DateTime;
use NeoFramework\Core\Jobs\Entity\JobEntity;
use NeoFramework\Core\Jobs\Interfaces\Client;
use Redis as PhpRedis;

class Redis implements Client
{
    private PhpRedis $redis;
    private string $prefix = 'neoframework:jobs:';
    private int $defaultJobTTL = 86400;

    public function __construct(array $config = [])
    {
        $host = $config['host'] ?? env("REDIS_HOST");
        $port = $config['port'] ?? env("REDIS_PORT");
        $password = $config['password'] ?? env("REDIS_PASSWORD", "");
        $this->prefix = $config['prefix'] ?? $this->prefix;

        if (!$host || !$port) {
            throw new Exception("Redis host or port not configured");
        }

        $redis = new PhpRedis();

        try {
            $redis->connect($host, $port);

            if ($password) {
                $redis->auth($password);
            }

            $this->redis = $redis;
        } catch (Exception $e) {
            throw new Exception("Failed to connect to Redis: " . $e->getMessage());
        }
    }

    /**
     * Generates a key with the prefix for the queue system
     */
    private function getKey(string $type, string $name): string
    {
        return "{$this->prefix}{$type}:{$name}";
    }

    /**
     * Adds a job to the queue
     */
    public function enqueue(JobEntity $job, string $queue = "default"): bool
    {
        try {
            // If job has future scheduling, add it to the scheduled queue
            if ($job->getSchedule() && $job->getSchedule() > new DateTime()) {
                return $this->scheduleJob($job, $queue);
            }

            // Otherwise, add it to the main queue
            $queueKey = $this->getKey('queue', $queue);
            $job->setStatus('pending');
            $result = $this->redis->lPush($queueKey, $job->toJson());

            // Store job details in a separate hash for easy access
            $this->storeJobDetails($job);

            return $result > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Adds a job to the scheduling queue
     */
    public function scheduleJob(JobEntity $job, string $queue = "default"): bool
    {
        try {
            $scheduledQueueKey = $this->getKey('scheduled', $queue);
            $job->setStatus('scheduled');

            $timestamp = $job->getSchedule()->getTimestamp();
            $result = $this->redis->zAdd($scheduledQueueKey, $timestamp, $job->toJson());

            // Store job details in a separate hash for easy access
            $this->storeJobDetails($job);

            return $result > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Stores job details in a separate hash for easy access
     */
    private function storeJobDetails(JobEntity $job): void
    {
        $jobKey = $this->getKey('details', $job->getId());
        $this->redis->hMSet($jobKey, $job->toArray());
        $this->redis->expire($jobKey, $this->defaultJobTTL);
    }

    /**
     * Removes and returns the next job from the queue
     */
    public function dequeue(string $queue = "default"): ?JobEntity
    {
        try {
            // Check if there are scheduled jobs ready to be executed
            $this->migrateScheduledJobs($queue);

            $queueKey = $this->getKey('queue', $queue);
            $item = $this->redis->rPop($queueKey);

            if (!$item) {
                return null;
            }

            $job = JobEntity::fromJson($item);
            $job->setStatus('processing');

            // Update job details
            $this->storeJobDetails($job);

            return $job;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Moves scheduled jobs that are due to the main queue
     */
    public function migrateScheduledJobs(string $queue = "default"): int
    {
        $scheduledQueueKey = $this->getKey('scheduled', $queue);
        $queueKey = $this->getKey('queue', $queue);
        $now = time();
        $count = 0;

        // Get all scheduled jobs that are due
        $jobs = $this->redis->zRangeByScore($scheduledQueueKey, 0, $now);

        if (!empty($jobs)) {
            foreach ($jobs as $jobJson) {
                if ($this->redis->lPush($queueKey, $jobJson)) {
                    $count++;

                    // Update job status
                    $job = JobEntity::fromJson($jobJson);
                    $job->setStatus('pending');
                    $this->storeJobDetails($job);
                }
            }

            // Remove migrated jobs from the scheduled queue
            $this->redis->zRemRangeByScore($scheduledQueueKey, 0, $now);
        }

        return $count;
    }

    /**
     * Returns scheduled jobs that are due
     */
    public function getDueJobs(string $queue = "default"): array
    {
        try {
            $scheduledQueueKey = $this->getKey('scheduled', $queue);
            $now = time();
            $jobsJson = $this->redis->zRangeByScore($scheduledQueueKey, 0, $now);

            $jobs = [];
            foreach ($jobsJson as $jobJson) {
                $jobs[] = JobEntity::fromJson($jobJson);
            }

            return $jobs;
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Returns the queue size
     */
    public function size(string $queue = "default"): int
    {
        try {
            $queueKey = $this->getKey('queue', $queue);
            return $this->redis->lLen($queueKey);
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Gets jobs from a queue without removing them
     */
    public function getJobs(string $queue = "default", int $limit = 10): array
    {
        try {
            $queueKey = $this->getKey('queue', $queue);
            $length = min($this->redis->lLen($queueKey), $limit);
            $jobs = [];

            for ($i = 0; $i < $length; $i++) {
                $jobJson = $this->redis->lIndex($queueKey, $i);
                if ($jobJson) {
                    $jobs[] = JobEntity::fromJson($jobJson);
                }
            }

            return $jobs;
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Adds a job back to the queue with attempt count
     */
    public function retry(JobEntity $job, string $queue = "default", int $attempts = 0): bool
    {
        if ($attempts > 0) {
            $job->setAttempts($attempts);
        } else {
            $job->incrementAttempts();
        }

        $job->setStatus('pending');
        $this->storeJobDetails($job);

        return $this->enqueue($job, $queue);
    }

    /**
     * Creates a lock for a specific job
     */
    public function lock(string $jobId, int $ttl = 60): bool
    {
        $lockKey = $this->getKey('lock', $jobId);
        return (bool) $this->redis->set($lockKey, '1', ['NX', 'EX' => $ttl]);
    }

    /**
     * Removes the lock from a job
     */
    public function unlock(string $jobId): bool
    {
        $lockKey = $this->getKey('lock', $jobId);
        return (bool) $this->redis->del($lockKey);
    }

    /**
     * Marks a job as completed
     */
    public function markAsCompleted(JobEntity $job,?string $result = null): bool
    {
        $job->setStatus('completed');
        if ($result) {
            $job->setResult($result);
        }
        $this->storeJobDetails($job);
        return true;
    }

    /**
     * Marks a job as failed
     */
    public function markAsFailed(JobEntity $job, string $error,string $queue = "default"): bool
    {
        $job->setStatus('failed');
        $job->setError($error);
        $this->storeJobDetails($job);

        $failedQueueKey = $this->getKey('failed',$queue);
        $this->redis->lPush($failedQueueKey, $job->toJson());

        return true;
    }

    /**
     * Completely clears a specific queue
     * 
     * @param string $queue Name of the queue to be cleared
     * @return int Number of jobs removed from the queue
     */
    public function clear(string $queue = "default"): int
    {
        try {
            $queueKey = $this->getKey('queue', $queue);
            $count = $this->redis->lLen($queueKey);

            // Delete the queue key
            $this->redis->del($queueKey);

            // Also clear the scheduled queue
            $scheduledQueueKey = $this->getKey('scheduled', $queue);
            $this->redis->del($scheduledQueueKey);

            return $count;
        } catch (Exception $e) {
            return 0;
        }
    }
}