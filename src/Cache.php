<?php

namespace NeoFramework\Core;

use Exception;
use Symfony\Component\Cache\Adapter\FilesystemTagAwareAdapter;
use Symfony\Component\Cache\Adapter\MemcachedAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

final class Cache {

    private static ?TagAwareCacheInterface $instance = null;

    private static function load(): TagAwareCacheInterface
    {
        if (self::$instance === null) {
            if (!isset($_ENV["CACHE_ADAPTER"])) {
                throw new Exception("CACHE_ADAPTER not found in the .env file, please configure the cache adapter");
            }

            $adapterType = strtolower($_ENV["CACHE_ADAPTER"] ?? 'filesystem');

            switch ($adapterType) {
                case 'memcached':
                    $baseAdapter = self::createMemcachedAdapter();
                    self::$instance = new TagAwareAdapter($baseAdapter);
                    break;
                case 'redis':
                    $baseAdapter = self::createRedisAdapter();
                    self::$instance = new TagAwareAdapter($baseAdapter);
                    break;

                case 'filesystem':
                default:
                    self::$instance = new FilesystemTagAwareAdapter(
                        directory: Functions::getRoot() . "Cache" 
                    );
                    break;
            }
        }

        return self::$instance;
    }

    private static function createMemcachedAdapter(): MemcachedAdapter
    {
        if (!isset($_ENV["MEMCACHED_CONNECTION_URL"])) {
            throw new Exception("MEMCACHED_CONNECTION_URL not found in the .env file. Please configure Memcached.");
        }

        try {
            $client = MemcachedAdapter::createConnection(
                $_ENV["MEMCACHED_CONNECTION_URL"]
            );
            return new MemcachedAdapter($client);
        } catch (\Exception $e) {
            throw new Exception("Failed to create Memcached connection: " . $e->getMessage(), 0, $e);
        }
    }

    private static function createRedisAdapter(): RedisAdapter
    {
        if (!isset($_ENV["REDIS_CONNECTION_URL"])) {
            throw new Exception("REDIS_CONNECTION_URL not found in the .env file. Please configure Redis.");
        }

        try {
            $client = RedisAdapter::createConnection(
                $_ENV["REDIS_CONNECTION_URL"]
            );
            return new RedisAdapter($client);
         } catch (\Exception $e) {
            throw new Exception("Failed to create Redis connection: " . $e->getMessage(), 0, $e);
        }
    }

    public function __call($name, $arguments): mixed
    {
        return self::load()->$name(...$arguments);
    }

    public static function __callStatic($name, $arguments): mixed
    {
        return self::load()->$name(...$arguments);
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}