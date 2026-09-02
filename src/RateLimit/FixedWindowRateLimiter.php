<?php
declare(strict_types=1);

namespace NeoFramework\Core\RateLimit;

final readonly class FixedWindowRateLimiter implements RateLimiterInterface
{
    public function __construct(private RateLimitStoreInterface $store, private ClockInterface $clock = new SystemClock()) {}

    public function attempt(string $key, int $limit, int $window): RateLimitResult
    {
        $now = $this->clock->now();
        $resetAt = (int) ((intdiv($now, $window) + 1) * $window);
        $count = $this->store->increment($key . ':' . $resetAt, $resetAt);

        return new RateLimitResult($count <= $limit, $limit, max(0, $limit - $count), $resetAt, max(1, $resetAt - $now));
    }
}
