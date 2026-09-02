<?php
declare(strict_types=1);

namespace NeoFramework\Core\RateLimit;

use NeoFramework\Core\Config\RateLimitConfig;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/** Monta o limitador declarado em `Config/rate_limit.php`. */
final class RateLimiterFactory
{
    public static function fromConfig(RateLimitConfig $config, LoggerInterface $logger = new NullLogger(), ClockInterface $clock = new SystemClock()): RateLimiterInterface
    {
        $store = self::storeFromConfig($config);

        $limiter = $config->strategy === 'sliding_window'
            ? new SlidingWindowRateLimiter($store, $clock)
            : new FixedWindowRateLimiter($store, $clock);

        return new ResilientRateLimiter($limiter, $config->onFailure, $logger, $clock);
    }

    /** O store cru, para quem conta algo que não é "requisições por janela". */
    public static function storeFromConfig(RateLimitConfig $config): RateLimitStoreInterface
    {
        return new LazyRateLimitStore(static fn (): RateLimitStoreInterface => $config->store === 'redis'
            ? RedisRateLimitStore::fromConfig($config)
            : new CacheRateLimitStore());
    }
}
