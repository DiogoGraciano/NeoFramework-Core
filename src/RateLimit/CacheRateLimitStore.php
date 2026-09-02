<?php
declare(strict_types=1);

namespace NeoFramework\Core\RateLimit;

use DateTimeImmutable;
use NeoFramework\Core\Cache;

/**
 * Baseline apoiado no cache configurado.
 *
 * O incremento é read-modify-write: dois processos que leem o mesmo valor gravam
 * o mesmo contador e o limite pode ser ultrapassado sob concorrência. Use
 * `RedisRateLimitStore` quando houver mais de um processo servindo tráfego.
 */
final readonly class CacheRateLimitStore implements RateLimitStoreInterface
{
    public function __construct(private Cache $cache = new Cache()) {}

    public function increment(string $key, int $expiresAt): int
    {
        $item = $this->cache->getItem(self::cacheKey($key));
        $count = $item->isHit() ? $item->get() : 0;
        if (!is_int($count)) $count = 0;
        $item->set(++$count)->expiresAt((new DateTimeImmutable())->setTimestamp($expiresAt));
        $this->cache->save($item);

        return $count;
    }

    public function read(string $key): int
    {
        $item = $this->cache->getItem(self::cacheKey($key));
        $count = $item->isHit() ? $item->get() : 0;

        return is_int($count) ? $count : 0;
    }

    public function forget(string $key): void
    {
        $this->cache->deleteItem(self::cacheKey($key));
    }

    /**
     * Chaves de rate limit carregam path, IP e timestamp, e o PSR-6 recusa
     * `{}()/\@:` — passar a chave crua faz toda requisição limitada estourar
     * `InvalidArgumentException` dentro do adapter de cache.
     */
    private static function cacheKey(string $key): string
    {
        return 'neoframework_rate_limit_' . hash('sha256', $key);
    }
}
