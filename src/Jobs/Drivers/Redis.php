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
     * Gera uma chave com o prefixo para o sistema de filas
     */
    private function getKey(string $type, string $name): string
    {
        return "{$this->prefix}{$type}:{$name}";
    }

    /**
     * Adiciona um job à fila
     */
    public function enqueue(JobEntity $job, string $queue = "default"): bool
    {
        try {
            // Se o job tem agendamento futuro, adiciona na fila de agendados
            if ($job->getSchedule() && $job->getSchedule() > new DateTime()) {
                return $this->scheduleJob($job, $queue);
            }

            // Caso contrário, adiciona na fila principal
            $queueKey = $this->getKey('queue', $queue);
            $job->setStatus('pending');
            $result = $this->redis->lPush($queueKey, $job->toJson());
            
            // Armazena detalhes do job em um hash separado
            $this->storeJobDetails($job);
            
            return $result > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Adiciona um job à fila de agendamento
     */
    public function scheduleJob(JobEntity $job, string $queue = "default"): bool
    {
        try {
            $scheduledQueueKey = $this->getKey('scheduled', $queue);
            $job->setStatus('scheduled');
            
            $timestamp = $job->getSchedule()->getTimestamp();
            $result = $this->redis->zAdd($scheduledQueueKey, $timestamp, $job->toJson());
            
            // Armazena detalhes do job em um hash separado
            $this->storeJobDetails($job);
            
            return $result > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Armazena detalhes do job em um hash separado para fácil acesso
     */
    private function storeJobDetails(JobEntity $job): void
    {
        $jobKey = $this->getKey('details', $job->getId());
        $this->redis->hMSet($jobKey, $job->toArray());
        $this->redis->expire($jobKey, $this->defaultJobTTL);
    }

    /**
     * Remove e retorna o próximo job da fila
     */
    public function dequeue(string $queue = "default"): ?JobEntity
    {
        try {
            // Verifica se há jobs agendados que já podem ser executados
            $this->migrateScheduledJobs($queue);
            
            $queueKey = $this->getKey('queue', $queue);
            $item = $this->redis->rPop($queueKey);
            
            if (!$item) {
                return null;
            }
            
            $job = JobEntity::fromJson($item);
            $job->setStatus('processing');
            
            // Atualiza os detalhes do job
            $this->storeJobDetails($job);
            
            return $job;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Move jobs agendados que já estão no prazo para a fila principal
     */
    public function migrateScheduledJobs(string $queue = "default"): int
    {
        $scheduledQueueKey = $this->getKey('scheduled', $queue);
        $queueKey = $this->getKey('queue', $queue);
        $now = time();
        $count = 0;
        
        // Busca todos os jobs agendados que já estão no prazo
        $jobs = $this->redis->zRangeByScore($scheduledQueueKey, 0, $now);
        
        if (!empty($jobs)) {
            foreach ($jobs as $jobJson) {
                if ($this->redis->lPush($queueKey, $jobJson)) {
                    $count++;
                    
                    // Atualiza o status do job
                    $job = JobEntity::fromJson($jobJson);
                    $job->setStatus('pending');
                    $this->storeJobDetails($job);
                }
            }
            
            // Remove os jobs migrados da fila de agendamento
            $this->redis->zRemRangeByScore($scheduledQueueKey, 0, $now);
        }
        
        return $count;
    }

    /**
     * Retorna os jobs agendados que já estão no prazo
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
     * Retorna o tamanho da fila
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
     * Obtém os jobs de uma fila sem removê-los
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
     * Adiciona um job à fila novamente com contagem de tentativas
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
     * Cria um bloqueio para um job específico
     */
    public function lock(string $jobId, int $ttl = 60): bool
    {
        $lockKey = $this->getKey('lock', $jobId);
        return (bool) $this->redis->set($lockKey, '1', ['NX', 'EX' => $ttl]);
    }

    /**
     * Remove o bloqueio de um job
     */
    public function unlock(string $jobId): bool
    {
        $lockKey = $this->getKey('lock', $jobId);
        return (bool) $this->redis->del($lockKey);
    }

    /**
     * Marca um job como concluído
     */
    public function markAsCompleted(JobEntity $job, ?string $result = null): bool
    {
        $job->setStatus('completed');
        if ($result) {
            $job->setResult($result);
        }
        $this->storeJobDetails($job);
        return true;
    }

    /**
     * Marca um job como falho
     */
    public function markAsFailed(JobEntity $job, string $error): bool
    {
        $job->setStatus('failed');
        $job->setError($error);
        $this->storeJobDetails($job);
        
        $failedQueueKey = $this->getKey('failed', 'default');
        $this->redis->lPush($failedQueueKey, $job->toJson());
        
        return true;
    }
}