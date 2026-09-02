<?php
declare(strict_types=1);

namespace NeoFramework\Core;

use Exception;
use NeoFramework\Core\Config\CacheConfig;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
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
            $config = CacheConfig::from(Config::repository());
            $adapterType = $config->adapter;

            switch ($adapterType) {
                case 'memcached':
                    $baseAdapter = self::createMemcachedAdapter($config);
                    self::$instance = new TagAwareAdapter($baseAdapter);
                    break;
                case 'redis':
                    $baseAdapter = self::createRedisAdapter($config);
                    self::$instance = new TagAwareAdapter($baseAdapter);
                    break;

                case 'filesystem':
                default:
                    self::$instance = new FilesystemTagAwareAdapter(
                        directory: \NeoFramework\Core\Support\ProjectRoot::path() . "Cache"
                    );
                    break;
            }
        }

        return self::$instance;
    }

    private static function createMemcachedAdapter(CacheConfig $config): MemcachedAdapter
    {
        if ($config->memcachedHost === '') {
            throw new Exception("cache.memcached.host must be configured for the memcached adapter.");
        }

        try {
            $user = $config->memcachedUser;
            $pass = $config->memcachedPassword;

            $credentials = ($user !== "" || $pass !== "")
                ? rawurlencode($user) . ":" . rawurlencode($pass) . "@"
                : "";

            $client = MemcachedAdapter::createConnection(
                "memcached://" . $credentials . $config->memcachedHost . ":" . $config->memcachedPort
            );
            return new MemcachedAdapter($client);
        } catch (\Exception $e) {
            throw new Exception("Failed to create Memcached connection: " . $e->getMessage(), 0, $e);
        }
    }

    private static function createRedisAdapter(CacheConfig $config): RedisAdapter
    {
        if ($config->redisHost === '') {
            throw new Exception("cache.redis.host must be configured for the redis adapter.");
        }

        try {
            $password = $config->redisPassword;

            // A senha precisa vir depois de ":" — sem os dois-pontos o Symfony
            // interpreta o valor como nome de usuário e a autenticação falha.
            $credentials = $password !== "" ? ":" . rawurlencode($password) . "@" : "";

            $client = RedisAdapter::createConnection(
                "redis://" . $credentials . $config->redisHost . ":" . $config->redisPort . "/0"
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

    public function getItem(string $key): CacheItemInterface
    {
        $cache = self::load();
        if (!$cache instanceof CacheItemPoolInterface) throw new \LogicException('Configured cache does not expose PSR-6 items.');
        $item = $cache->getItem($key);

        // Aqui é o único ponto onde acerto e erro são distinguíveis: quem chama
        // recebe o item e decide sozinho o que fazer com `isHit()`.
        \NeoFramework\Core\Events\Events::dispatch(new \NeoFramework\Core\Events\CacheAccessed($item->isHit()));

        return $item;
    }

    public function save(CacheItemInterface $item): bool
    {
        $cache = self::load();
        if (!$cache instanceof CacheItemPoolInterface) throw new \LogicException('Configured cache does not expose PSR-6 items.');
        return $cache->save($item);
    }

    public function deleteItem(string $key): bool
    {
        $cache = self::load();
        if (!$cache instanceof CacheItemPoolInterface) throw new \LogicException('Configured cache does not expose PSR-6 items.');
        return $cache->deleteItem($key);
    }

    public static function __callStatic($name, $arguments): mixed
    {
        return self::load()->$name(...$arguments);
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Instala um pool no lugar do configurado.
     *
     * Um teste que precisa de cache não deveria escrever em `Cache/` nem exigir
     * Redis: o primeiro deixa resto entre execuções, o segundo torna a suíte
     * dependente de serviço externo. `null` devolve o comportamento normal.
     */
    public static function swap(?TagAwareCacheInterface $cache): void
    {
        self::$instance = $cache;
    }
}
