<?php

namespace NeoFramework\Core\Jobs\Interfaces;
use NeoFramework\Core\Jobs\Entity\JobEntity;

interface Client
{
    public function enqueue(JobEntity $job, string $queue = "default"): bool;
    public function dequeue(string $queue = "default"): ?JobEntity;
    public function size(string $queue = "default"): int;
    public function clear(string $queue = "default"): int;
    public function getJobs(string $queue = "default", int $limit = 10): array;
    public function lock(string $jobId, int $ttl = 60): bool;
    public function unlock(string $jobId): bool;
    public function retry(JobEntity $job, string $queue = "default", int $attempts = 0): bool;
    public function scheduleJob(JobEntity $job, string $queue = "default"): bool;
    public function getDueJobs(string $queue = "default"): array;
    public function migrateScheduledJobs(string $queue = "default"): int;
    public function markAsCompleted(JobEntity $job, ?string $result = null): bool;
    public function markAsFailed(JobEntity $job, string $error): bool;
}
