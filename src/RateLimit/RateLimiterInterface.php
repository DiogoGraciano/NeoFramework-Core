<?php
declare(strict_types=1);

namespace NeoFramework\Core\RateLimit;

interface RateLimiterInterface
{
    public function attempt(string $key, int $limit, int $window): RateLimitResult;
}
