<?php
declare(strict_types=1);

namespace NeoFramework\Core\RateLimit;

use InvalidArgumentException;
use NeoFramework\Core\Config\RateLimitConfig;

final readonly class RateLimitPolicyRegistry
{
    /** @param array<string,RateLimitPolicy> $policies */
    public function __construct(private array $policies = []) {}

    public static function fromConfig(RateLimitConfig $config): self
    {
        // As políticas já foram validadas no bootstrap: um nome inválido derruba
        // a aplicação antes de servir a primeira requisição, não durante ela.
        return new self($config->policies);
    }

    /** @param array{limit?:int,window?:int,key?:string,policy?:string} $policy */
    public function resolve(array $policy): RateLimitPolicy
    {
        $name = $policy['policy'] ?? null;
        if ($name !== null) {
            if (!isset($this->policies[$name])) throw new InvalidArgumentException("Unknown rate limit policy: {$name}");

            return $this->policies[$name];
        }

        return new RateLimitPolicy($policy['limit'] ?? 0, $policy['window'] ?? 0, RateLimitKey::from($policy['key'] ?? RateLimitKey::UserOrIp->value));
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->policies);
    }
}
