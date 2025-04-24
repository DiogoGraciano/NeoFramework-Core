<?php

namespace NeoFramework\Core\Abstract;

use NeoFramework\Core\Jobs\Entity\JobEntity;
use NeoFramework\Core\Jobs\QueueManager;

abstract class Job
{
    abstract public function handle();
    
    public static function dispatch(array $args = [], ?\DateTime $schedule = null,string $queue = "default"): JobEntity
    {
        $job = new JobEntity(static::class, $args, $schedule);
        $queueManager = QueueManager::getInstance();
        $queueManager->getClient()->enqueue($job,$queue);
        return $job;
    }
    
    public static function later(\DateTime $schedule, array $args = [],string $queue = "default"): JobEntity
    {
        return self::dispatch($args, $schedule, $queue);
    }
}
