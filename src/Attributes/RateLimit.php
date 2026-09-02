<?php
declare(strict_types=1);

namespace NeoFramework\Core\Attributes;

use NeoFramework\Core\RateLimit\RateLimitKey;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class RateLimit
{
    public function __construct(public ?int $limit = null, public ?int $window = null, public RateLimitKey $key = RateLimitKey::UserOrIp, public ?string $policy = null)
    {
        if ($policy === null && ($limit === null || $window === null)) throw new \InvalidArgumentException('A rate limit policy or explicit limit and window are required.');
        if ($policy === null && ($limit < 1 || $window < 1)) throw new \InvalidArgumentException('Rate limit and window must be positive.');
    }

    /** @return array{limit?:int,window?:int,key:string,policy?:string} */
    public function toArray(): array
    {
        return array_filter(['limit' => $this->limit, 'window' => $this->window, 'key' => $this->key->value, 'policy' => $this->policy], static fn (mixed $value): bool => $value !== null);
    }
}
