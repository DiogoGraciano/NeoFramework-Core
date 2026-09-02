<?php
declare(strict_types=1);

namespace NeoFramework\Core\Config;

use NeoFramework\Core\RateLimit\RateLimitFailureStrategy;
use NeoFramework\Core\RateLimit\RateLimitKey;
use NeoFramework\Core\RateLimit\RateLimitPolicy;

final readonly class RateLimitConfig
{
    /** @param array<string,RateLimitPolicy> $policies */
    private function __construct(
        public string $store,
        public string $strategy,
        public RateLimitFailureStrategy $onFailure,
        public string $redisHost,
        public int $redisPort,
        public string $redisPassword,
        public string $redisPrefix,
        public float $redisTimeout,
        public array $policies,
    ) {}

    public static function from(ConfigRepositoryInterface $c): self
    {
        $store = ConfigValidator::string($c, 'rate_limit.store', 'cache');
        if (!in_array($store, ['cache', 'redis'], true)) throw new ConfigurationException("Configuration key 'rate_limit.store' must be 'cache' or 'redis'.");

        $strategy = ConfigValidator::string($c, 'rate_limit.strategy', 'fixed_window');
        if (!in_array($strategy, ['fixed_window', 'sliding_window'], true)) throw new ConfigurationException("Configuration key 'rate_limit.strategy' must be 'fixed_window' or 'sliding_window'.");

        $onFailure = RateLimitFailureStrategy::tryFrom(ConfigValidator::string($c, 'rate_limit.on_failure', 'open'))
            ?? throw new ConfigurationException("Configuration key 'rate_limit.on_failure' must be 'open' or 'closed'.");

        $host = ConfigValidator::string($c, 'rate_limit.redis.host');
        $port = ConfigValidator::int($c, 'rate_limit.redis.port', 6379);
        if ($store === 'redis' && $host === '') throw new ConfigurationException("Configuration key 'rate_limit.redis.host' is required by the redis store.");
        if ($port < 1) throw new ConfigurationException("Configuration key 'rate_limit.redis.port' must be a positive integer.");

        $timeout = $c->get('rate_limit.redis.timeout', 0.5);
        if (!is_float($timeout) && !is_int($timeout)) throw new ConfigurationException("Configuration key 'rate_limit.redis.timeout' must be a number.");
        if ((float) $timeout <= 0) throw new ConfigurationException("Configuration key 'rate_limit.redis.timeout' must be positive.");

        return new self(
            $store,
            $strategy,
            $onFailure,
            $host,
            $port,
            ConfigValidator::string($c, 'rate_limit.redis.password'),
            ConfigValidator::string($c, 'rate_limit.redis.prefix', 'neoframework:rl:'),
            (float) $timeout,
            self::policies($c),
        );
    }

    /** @return array<string,RateLimitPolicy> */
    private static function policies(ConfigRepositoryInterface $c): array
    {
        $raw = $c->get('rate_limit.policies', []);
        if (!is_array($raw)) throw new ConfigurationException("Configuration key 'rate_limit.policies' must be a map of named policies.");

        $policies = [];
        foreach ($raw as $name => $policy) {
            if (!is_string($name) || !is_array($policy)) throw new ConfigurationException("Configuration key 'rate_limit.policies' must be a map of named policies.");

            $limit = $policy['limit'] ?? null;
            $window = $policy['window'] ?? null;
            if (!is_int($limit) || !is_int($window)) throw new ConfigurationException("Rate limit policy '{$name}' must declare integer 'limit' and 'window'.");
            if ($limit < 1 || $window < 1) throw new ConfigurationException("Rate limit policy '{$name}' must declare a positive 'limit' and 'window'.");

            $key = $policy['key'] ?? RateLimitKey::UserOrIp->value;
            if (!is_string($key) || RateLimitKey::tryFrom($key) === null) throw new ConfigurationException("Rate limit policy '{$name}' declares an unknown key.");

            $policies[$name] = new RateLimitPolicy($limit, $window, RateLimitKey::from($key));
        }

        return $policies;
    }
}
