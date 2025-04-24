<?php

namespace NeoFramework\Core\Jobs\Drivers;

use Exception;
use DateTime;
use NeoFramework\Core\Jobs\Entity\JobEntity;
use NeoFramework\Core\Jobs\Interfaces\Client;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class Files implements Client
{
    private string $storagePath;
    private int $defaultJobTTL = 86400; // TTL for job details (requires manual cleanup)
    private int $lockTTL = 60; // Default TTL for locks

    /**
     * @param array $config Configuration array. Expects 'path'.
     * @throws Exception If storage path is not configured or not writable.
     */
    public function __construct(array $config = [])
    {
        $this->storagePath = $config['path'] ?? env("JOBS_STORAGE_PATH") ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'neoframework_jobs';
        $this->defaultJobTTL = $config['defaultJobTTL'] ?? $this->defaultJobTTL;
        $this->lockTTL = $config['lockTTL'] ?? $this->lockTTL;

        if (empty($this->storagePath)) {
            throw new Exception("Filesystem job storage path not configured.");
        }

        // Ensure base directory and subdirectories exist and are writable
        $this->ensureDirectoryExists($this->getQueuePath('default'));
        $this->ensureDirectoryExists($this->getScheduledPath('default'));
        $this->ensureDirectoryExists($this->getDetailsPath('')); // Base details dir
        $this->ensureDirectoryExists($this->getLockPath(''));   // Base lock dir
        $this->ensureDirectoryExists($this->getFailedPath('default'));
    }

    /**
     * Ensures a directory exists and is writable.
     *
     * @param string $path
     * @throws Exception
     */
    private function ensureDirectoryExists(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new Exception("Failed to create directory: {$path}");
        }
        if (!is_writable($path)) {
            throw new Exception("Directory is not writable: {$path}");
        }
    }

    // --- Path Generation Methods ---

    private function getQueuePath(string $queue): string
    {
        return $this->storagePath . DIRECTORY_SEPARATOR . 'queues' . DIRECTORY_SEPARATOR . $queue;
    }

    private function getScheduledPath(string $queue): string
    {
        return $this->storagePath . DIRECTORY_SEPARATOR . 'scheduled' . DIRECTORY_SEPARATOR . $queue;
    }

    private function getDetailsPath(string $jobId): string
    {
        // Use subdirectories for potentially large number of jobs
        $prefix = substr($jobId, 0, 2);
        $path = $this->storagePath . DIRECTORY_SEPARATOR . 'details' . DIRECTORY_SEPARATOR . $prefix;
        if ($jobId) {
            return $path . DIRECTORY_SEPARATOR . $jobId . '.json';
        }
        return $path; // Return base path if no ID
    }

    private function getLockPath(string $jobId): string
    {
        $path = $this->storagePath . DIRECTORY_SEPARATOR . 'locks';
         if ($jobId) {
            return $path . DIRECTORY_SEPARATOR . $jobId . '.lock';
        }
        return $path; // Return base path if no ID
    }

     private function getFailedPath(string $queue): string
    {
        return $this->storagePath . DIRECTORY_SEPARATOR . 'failed' . DIRECTORY_SEPARATOR . $queue;
    }


    /**
     * Generates a unique filename for a job in a queue.
     * Includes timestamp for sorting.
     */
    private function generateJobFilename(JobEntity $job, ?int $timestamp = null): string
    {
        // Use high-resolution time for enqueue, scheduled time for schedule
        $prefixTime = $timestamp ?? microtime(true);
        // Format ensures correct lexicographical sorting
        return sprintf('%016.6f', $prefixTime) . '_' . $job->getId() . '.json';
    }

    /**
     * Adds a job to the queue.
     */
    public function enqueue(JobEntity $job, string $queue = "default"): bool
    {
        try {
            // If job has future scheduling, add it to the scheduled queue
            if ($job->getSchedule() && $job->getSchedule() > new DateTime()) {
                return $this->scheduleJob($job, $queue);
            }

            // Otherwise, add it to the main queue
            $queuePath = $this->getQueuePath($queue);
            $this->ensureDirectoryExists($queuePath); // Ensure queue specific dir exists

            $job->setStatus('pending');
            $filename = $this->generateJobFilename($job);
            $filePath = $queuePath . DIRECTORY_SEPARATOR . $filename;

            // Store job details separately first
            $this->storeJobDetails($job);

            // Write job file (atomicity depends on filesystem)
            return file_put_contents($filePath, $job->toJson()) !== false;

        } catch (Exception $e) {
            // Log error $e->getMessage();
            return false;
        }
    }

    /**
     * Adds a job to the scheduling queue.
     */
    public function scheduleJob(JobEntity $job, string $queue = "default"): bool
    {
         if (!$job->getSchedule()) {
             // Maybe throw an exception or log? Cannot schedule without a date.
             return false;
         }

        try {
            $scheduledPath = $this->getScheduledPath($queue);
            $this->ensureDirectoryExists($scheduledPath); // Ensure queue specific dir exists

            $job->setStatus('scheduled');
            $timestamp = $job->getSchedule()->getTimestamp();
            $filename = $this->generateJobFilename($job, $timestamp);
            $filePath = $scheduledPath . DIRECTORY_SEPARATOR . $filename;

            // Store job details separately first
            $this->storeJobDetails($job);

            // Write scheduled job file
            return file_put_contents($filePath, $job->toJson()) !== false;

        } catch (Exception $e) {
            // Log error $e->getMessage();
            return false;
        }
    }

    /**
     * Stores job details in a separate file.
     * Note: Filesystem doesn't have automatic TTL like Redis expire.
     * Cleanup needs to be handled externally based on file modification time or internal timestamp.
     */
    private function storeJobDetails(JobEntity $job): void
    {
        $detailsPath = $this->getDetailsPath($job->getId());
        $this->ensureDirectoryExists(dirname($detailsPath)); // Ensure subdirectory exists

        // Add expiry timestamp to the data itself for potential cleanup scripts
        $data = $job->toArray();
        $data['__expires_at'] = time() + $this->defaultJobTTL;

        file_put_contents($detailsPath, json_encode($data, JSON_PRETTY_PRINT));
        // No direct equivalent to Redis EXPIRE here.
    }

    /**
     * Removes and returns the next job from the queue.
     * Tries to be atomic using rename.
     */
    public function dequeue(string $queue = "default"): ?JobEntity
    {
        try {
            // Check if there are scheduled jobs ready to be executed
            $this->migrateScheduledJobs($queue);

            $queuePath = $this->getQueuePath($queue);
            if (!is_dir($queuePath)) return null; // Queue doesn't exist

            // Find the oldest job file
            $oldestFile = null;
            $oldestTimestamp = PHP_FLOAT_MAX;

            foreach (new FilesystemIterator($queuePath) as $fileinfo) {
                if ($fileinfo->isFile() && $fileinfo->getExtension() === 'json') {
                    // Extract timestamp from filename
                    $filename = $fileinfo->getFilename();
                    $parts = explode('_', $filename, 2);
                    if (count($parts) === 2 && is_numeric($parts[0])) {
                        $timestamp = (float)$parts[0];
                         if ($timestamp < $oldestTimestamp) {
                            $oldestTimestamp = $timestamp;
                            $oldestFile = $fileinfo->getPathname();
                        }
                    }
                }
            }

            if (!$oldestFile) {
                return null; // Queue is empty
            }

            // Attempt to atomically rename the file to "claim" it (lessens race conditions)
            // This is not perfectly atomic across all filesystems/setups but better than nothing.
            $processingFile = $oldestFile . '.processing';
            if (!@rename($oldestFile, $processingFile)) {
                // Failed to rename, likely another worker got it, or permissions issue
                return null;
            }

            $item = file_get_contents($processingFile);
            unlink($processingFile); // Delete the claimed file

            if (!$item) {
                return null; // Should not happen if rename succeeded, but check anyway
            }

            $job = JobEntity::fromJson($item);
            $job->setStatus('processing');

            // Update job details
            $this->storeJobDetails($job);

            return $job;

        } catch (Exception $e) {
            // Log error $e->getMessage();
            // If rename failed partially, $processingFile might exist, needs cleanup logic
            return null;
        }
    }

    /**
     * Moves scheduled jobs that are due to the main queue.
     */
    public function migrateScheduledJobs(string $queue = "default"): int
    {
        $scheduledPath = $this->getScheduledPath($queue);
        $queuePath = $this->getQueuePath($queue);
        if (!is_dir($scheduledPath)) return 0; // No scheduled jobs for this queue

        $this->ensureDirectoryExists($queuePath); // Ensure destination exists

        $now = time();
        $count = 0;

        foreach (new FilesystemIterator($scheduledPath) as $fileinfo) {
            if ($fileinfo->isFile() && $fileinfo->getExtension() === 'json') {
                $filename = $fileinfo->getFilename();
                $parts = explode('_', $filename, 2);
                // Check if filename conforms and time is due
                if (count($parts) === 2 && is_numeric($parts[0]) && (float)$parts[0] <= $now) {
                    $sourcePath = $fileinfo->getPathname();
                    // Use original job ID and current time for the new filename in the main queue
                    $jobJson = file_get_contents($sourcePath);
                    if (!$jobJson) continue; // Skip if read fails

                    try {
                         $job = JobEntity::fromJson($jobJson);
                         // Generate a new filename based on *current* time for FIFO in main queue
                         $newFilename = $this->generateJobFilename($job);
                         $destinationPath = $queuePath . DIRECTORY_SEPARATOR . $newFilename;

                        // Move the file
                        if (@rename($sourcePath, $destinationPath)) {
                            // Update status in details file
                            $job->setStatus('pending');
                            $this->storeJobDetails($job);
                            $count++;
                        } else {
                            // Log failure to move file
                        }
                    } catch (Exception $e) {
                         // Log error processing/moving job $filename: $e->getMessage()
                         // Consider moving to a 'corrupted' or 'error' state?
                         @unlink($sourcePath); // Attempt cleanup
                    }
                }
            }
        }

        return $count;
    }

    /**
     * Returns scheduled jobs that are due (without removing them).
     */
    public function getDueJobs(string $queue = "default"): array
    {
        $jobs = [];
        $scheduledPath = $this->getScheduledPath($queue);
        if (!is_dir($scheduledPath)) return [];

        $now = time();

        try {
            foreach (new FilesystemIterator($scheduledPath) as $fileinfo) {
                if ($fileinfo->isFile() && $fileinfo->getExtension() === 'json') {
                    $filename = $fileinfo->getFilename();
                    $parts = explode('_', $filename, 2);
                     // Check if filename conforms and time is due
                    if (count($parts) === 2 && is_numeric($parts[0]) && (float)$parts[0] <= $now) {
                        $jobJson = file_get_contents($fileinfo->getPathname());
                        if ($jobJson) {
                            $jobs[] = JobEntity::fromJson($jobJson);
                        }
                    }
                }
            }
            // Sort jobs by schedule time (ascending) based on filename timestamp
            usort($jobs, function(JobEntity $a, JobEntity $b) {
                // We need the original schedule time for comparison, which is in the object
                $tsA = $a->getSchedule() ? $a->getSchedule()->getTimestamp() : 0;
                $tsB = $b->getSchedule() ? $b->getSchedule()->getTimestamp() : 0;
                return $tsA <=> $tsB;
            });
        } catch (Exception $e) {
             // Log error $e->getMessage();
             return [];
        }

        return $jobs;
    }


    /**
     * Returns the queue size.
     */
    public function size(string $queue = "default"): int
    {
        $queuePath = $this->getQueuePath($queue);
        if (!is_dir($queuePath)) return 0;

        try {
            // Count only .json files to avoid counting temporary files like '.processing'
            $count = 0;
            foreach (new FilesystemIterator($queuePath) as $fileinfo) {
                if ($fileinfo->isFile() && $fileinfo->getExtension() === 'json') {
                    $count++;
                }
            }
            return $count;
        } catch (Exception $e) {
            // Log error $e->getMessage();
            return 0;
        }
    }

    /**
     * Gets jobs from a queue without removing them.
     */
    public function getJobs(string $queue = "default", int $limit = 10): array
    {
        $jobs = [];
        $files = [];
        $queuePath = $this->getQueuePath($queue);
        if (!is_dir($queuePath)) return [];

        try {
             // Get all json files
            foreach (new FilesystemIterator($queuePath) as $fileinfo) {
                 if ($fileinfo->isFile() && $fileinfo->getExtension() === 'json') {
                    $files[] = $fileinfo->getPathname();
                }
            }

            // Sort files by name (which includes timestamp)
            sort($files);

            // Take the first $limit files
            $filesToProcess = array_slice($files, 0, $limit);

            foreach ($filesToProcess as $filePath) {
                 $jobJson = file_get_contents($filePath);
                 if ($jobJson) {
                     $jobs[] = JobEntity::fromJson($jobJson);
                 }
            }
        } catch (Exception $e) {
            // Log error $e->getMessage();
            return [];
        }

        return $jobs;
    }

    /**
     * Adds a job back to the queue with attempt count.
     */
    public function retry(JobEntity $job, string $queue = "default", int $attempts = 0): bool
    {
        if ($attempts > 0) {
            $job->setAttempts($attempts);
        } else {
            $job->incrementAttempts();
        }

        $job->setStatus('pending');
        // Update details first
        $this->storeJobDetails($job);

        // Re-enqueue like a new job, gets a new timestamp filename
        return $this->enqueue($job, $queue);
    }

    /**
     * Creates a lock file for a specific job.
     * Uses file creation ('x' mode) for atomicity attempt.
     * Checks modification time for TTL.
     */
    public function lock(string $jobId, int $ttl = null): bool
    {
        $lockPath = $this->getLockPath($jobId);
        $this->ensureDirectoryExists(dirname($lockPath));
        $effectiveTTL = $ttl ?? $this->lockTTL;

        // Check if lock exists and is expired
        if (is_file($lockPath)) {
            $mtime = filemtime($lockPath);
            if ($mtime !== false && (time() - $mtime) > $effectiveTTL) {
                // Lock is expired, try to remove it
                @unlink($lockPath);
            } else {
                // Lock exists and is not expired
                return false;
            }
        }

        // Try to create the lock file exclusively
        $handle = @fopen($lockPath, 'x');
        if ($handle === false) {
            // Failed to create, likely already exists (race condition)
            return false;
        }

        // Write TTL info (optional, mainly for debugging)
        fwrite($handle, "Locked at: " . date('Y-m-d H:i:s') . ", TTL: " . $effectiveTTL);
        fclose($handle);

        return true;
    }

    /**
     * Removes the lock file from a job.
     */
    public function unlock(string $jobId): bool
    {
        $lockPath = $this->getLockPath($jobId);
        if (is_file($lockPath)) {
            return @unlink($lockPath);
        }
        return true; // Lock didn't exist, so it's effectively unlocked
    }

    /**
     * Marks a job as completed by updating its details file.
     */
    public function markAsCompleted(JobEntity $job, ?string $result = null): bool
    {
        try {
            $job->setStatus('completed');
            if ($result !== null) {
                $job->setResult($result);
            }
            $this->storeJobDetails($job); // Overwrites the details file with new status/result
            return true;
        } catch (Exception $e) {
             // Log error $e->getMessage();
            return false;
        }
    }

    /**
     * Marks a job as failed by updating details and moving to failed queue.
     */
    public function markAsFailed(JobEntity $job, string $error,string $queue = "default"): bool
    {
         try {
            $job->setStatus('failed');
            $job->setError($error);

            // Update details file first
            $this->storeJobDetails($job);

            // Move/Copy job data to the failed queue directory
            $failedPath = $this->getFailedPath($queue); // Use original queue if available
            $this->ensureDirectoryExists($failedPath);
            $failedFilename = $this->generateJobFilename($job); // Use current time
            $failedFilePath = $failedPath . DIRECTORY_SEPARATOR . $failedFilename;

            // Store the final state (including error) in the failed queue
            file_put_contents($failedFilePath, $job->toJson());

            return true;
        } catch (Exception $e) {
             // Log error $e->getMessage();
            return false;
        }
    }

    /**
     * Completely clears a specific queue (main and scheduled).
     * WARNING: This deletes files permanently.
     *
     * @param string $queue Name of the queue to be cleared
     * @return int Approximate number of jobs removed (might not be perfectly accurate if errors occur)
     */
    public function clear(string $queue = "default"): int
    {
        $count = 0;
        $pathsToClear = [
            $this->getQueuePath($queue),
            $this->getScheduledPath($queue),
            // Optionally clear related failed jobs?
            // $this->getFailedPath($queue),
        ];

        foreach ($pathsToClear as $path) {
            if (!is_dir($path)) continue;

            try {
                $iterator = new FilesystemIterator($path);
                foreach ($iterator as $fileinfo) {
                    if ($fileinfo->isFile()) {
                         if (@unlink($fileinfo->getPathname())) {
                            $count++;
                         } else {
                             // Log failure to delete file
                         }
                    }
                }
                // Optionally remove the directory itself if empty, but might be better to keep it
                // @rmdir($path);
            } catch (Exception $e) {
                // Log error clearing path $path: $e->getMessage();
            }
        }
        return $count;
    }

    /**
     * Cleans up expired job details files.
     * This needs to be called periodically (e.g., via cron).
     *
     * @return int Number of expired detail files removed.
     */
    public function cleanupExpiredDetails(): int
    {
        $detailsBasePath = $this->storagePath . DIRECTORY_SEPARATOR . 'details';
        if (!is_dir($detailsBasePath)) return 0;

        $count = 0;
        $now = time();

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($detailsBasePath, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $fileinfo) {
                if ($fileinfo->isFile() && $fileinfo->getExtension() === 'json') {
                    $content = @file_get_contents($fileinfo->getPathname());
                    if ($content) {
                        $data = json_decode($content, true);
                        // Check for our custom expiry field
                        if (isset($data['__expires_at']) && is_numeric($data['__expires_at']) && $data['__expires_at'] < $now) {
                            if (@unlink($fileinfo->getPathname())) {
                                $count++;
                            } else {
                                // Log unlink failure
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // Log cleanup error: $e->getMessage()
        }
         // Clean up potentially empty subdirectories in 'details'
         $this->cleanupEmptyDirs($detailsBasePath);

        return $count;
    }

    /**
    * Cleans up expired lock files.
    * Call periodically.
    * @return int Number of expired lock files removed.
    */
    public function cleanupExpiredLocks(): int
    {
        $lockBasePath = $this->getLockPath(''); // Get base lock dir path
        if (!is_dir($lockBasePath)) return 0;

        $count = 0;
        $now = time();

        try {
            $iterator = new FilesystemIterator($lockBasePath);
            foreach ($iterator as $fileinfo) {
                if ($fileinfo->isFile() && $fileinfo->getExtension() === 'lock') {
                    $mtime = $fileinfo->getMTime();
                    // Check if lock file is older than the *default* lock TTL
                    // Individual locks might have custom TTLs, but basic cleanup uses default
                    if (($now - $mtime) > $this->lockTTL) {
                        if (@unlink($fileinfo->getPathname())) {
                            $count++;
                        } else {
                            // Log unlink failure
                        }
                    }
                }
            }
        } catch (Exception $e) {
             // Log cleanup error: $e->getMessage()
        }
        return $count;
    }

    /**
     * Recursively removes empty directories.
     * @param string $dir Path to the directory.
     */
    private function cleanupEmptyDirs(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $fileinfo) {
                if ($fileinfo->isDir()) {
                    // Check if directory is empty using FilesystemIterator
                    $isEmpty = !(new FilesystemIterator($fileinfo->getPathname()))->valid();
                    if ($isEmpty) {
                        @rmdir($fileinfo->getPathname());
                    }
                }
            }
            // Final check for the top-level directory itself
            $isEmpty = !(new FilesystemIterator($dir))->valid();
            if ($isEmpty) {
                @rmdir($dir);
            }

        } catch(Exception $e) {
            // Log error during empty dir cleanup: $e->getMessage()
        }
    }
}