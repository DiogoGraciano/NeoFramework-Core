<?php

namespace NeoFramework\Core;

use Exception;
use Symfony\Component\Cache\Adapter\FilesystemTagAwareAdapter;
use Symfony\Component\Cache\Adapter\MemcachedAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

final class Cache
{
    private static function load(): TagAwareAdapter
    {
        if (!isset($_ENV["CACHE_ADAPTER"])) {
            throw new Exception("CACHE_ADAPTER not found in the .env file, configure the cache adapter in the .env file");
        }

        $adapter = match ($_ENV["CACHE_ADAPTER"]) {
            'memcached' => self::createMemcachedAdapter(),
            'redis' => self::createRedisAdapter(),
            default => new FilesystemTagAwareAdapter(directory: Functions::getRoot() . "Cache")
        };

        return new TagAwareAdapter($adapter);
    }

    private static function createMemcachedAdapter(): MemcachedAdapter
    {
        if (!isset($_ENV["MEMCACHED_CONNECTION_URL"])) {
            throw new Exception("MEMCACHED_CONNECTION_URL not found in the .env file, configure memcached in the .env file");
        }

        $client = MemcachedAdapter::createConnection(
            $_ENV["MEMCACHED_CONNECTION_URL"]
        );
        return new MemcachedAdapter($client);
    }

    private static function createRedisAdapter(): RedisAdapter
    {
        if (!isset($_ENV["REDIS_CONNECTION_URL"])) {
            throw new Exception("REDIS_CONNECTION_URL not found in the .env file, configure redis in the .env file");
        }

        $client = RedisAdapter::createConnection(
            $_ENV["REDIS_CONNECTION_URL"]
        );
        return new RedisAdapter($client);
    }

    public function __call($name, $arguments): mixed
    {
        return self::load()->$name(...$arguments);
    }

    public static function __callStatic($name, $arguments): mixed
    {
        return self::load()->$name(...$arguments);
    }
}