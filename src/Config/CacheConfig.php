<?php
declare(strict_types=1);
namespace NeoFramework\Core\Config;

final readonly class CacheConfig {
    private function __construct(public string $adapter, public string $redisHost, public int $redisPort, public string $redisPassword, public string $memcachedHost, public int $memcachedPort, public string $memcachedUser, public string $memcachedPassword) {}
    public static function from(ConfigRepositoryInterface $c): self {
        $adapter = ConfigValidator::string($c, 'cache.adapter', 'filesystem');
        if (!in_array($adapter, ['filesystem', 'redis', 'memcached'], true)) throw new ConfigurationException("Configuration key 'cache.adapter' is unsupported.");
        $self = new self($adapter, ConfigValidator::string($c, 'cache.redis.host'), ConfigValidator::int($c, 'cache.redis.port', 6379), ConfigValidator::string($c, 'cache.redis.password'), ConfigValidator::string($c, 'cache.memcached.host'), ConfigValidator::int($c, 'cache.memcached.port', 11211), ConfigValidator::string($c, 'cache.memcached.user'), ConfigValidator::string($c, 'cache.memcached.password'));
        if (($self->adapter === 'redis' && $self->redisHost === '') || ($self->adapter === 'memcached' && $self->memcachedHost === '')) throw new ConfigurationException("The selected cache adapter requires its host to be configured.");
        if ($self->redisPort < 1 || $self->memcachedPort < 1) throw new ConfigurationException('Cache ports must be positive integers.');
        return $self;
    }
}
