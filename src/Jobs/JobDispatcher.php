<?php

namespace NeoFramework\Core\Jobs;
use NeoFramework\Core\Interfaces\Job;

class JobDispatcher
{
    public function __construct(private Job $class,private array $args = [],private string $queue = "default")
    {
    }

    public function getClient(){
        $driver = env("QUEUE_DRIVER");
    }
}
