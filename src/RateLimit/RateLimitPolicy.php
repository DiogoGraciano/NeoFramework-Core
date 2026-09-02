<?php
declare(strict_types=1);

namespace NeoFramework\Core\RateLimit;

use InvalidArgumentException;

final readonly class RateLimitPolicy
{
    public function __construct(public int $limit, public int $window, public RateLimitKey $key = RateLimitKey::UserOrIp)
    {
        if ($limit < 1 || $window < 1) throw new InvalidArgumentException('Rate limit and window must be positive.');
    }
}
