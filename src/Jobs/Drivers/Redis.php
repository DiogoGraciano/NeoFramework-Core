<?php

namespace NeoFramework\Core\Jobs\Drivers;

use Exception;
use NeoFramework\Core\Jobs\Entity\JobEntity;
use NeoFramework\Core\Jobs\Interfaces\Client;
use Redis as PhpRedis;

class Redis implements Client
{
    private PhpRedis $redis;

    public function __construct()
    {
        if (!env("REDIS_HOST") || !env("REDIS_PORT")) {
            throw new Exception("REDIS_HOST or REDIS_PORT not found in the .env file. Please configure Redis.");
        }

        $redis = new PhpRedis;
        $redis->connect(env("REDIS_HOST"),env("REDIS_PORT"));

        $password = env("REDIS_PASSWORD");
        if($password != "")
            $redis->auth($password);

        $this->redis = $redis;
    }

    public function enqueue(JobEntity $job,string $queue = "default"): bool
    {
        $this->redis->lPush($queue,$job->toJson());
        return true;
    }
}
