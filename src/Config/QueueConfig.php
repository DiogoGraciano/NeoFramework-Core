<?php
declare(strict_types=1);
namespace NeoFramework\Core\Config;

final readonly class QueueConfig {
    private function __construct(public string $driver, public string $filesPath, public int $defaultTtl, public int $lockTtl, public string $redisHost, public int $redisPort, public string $redisPassword, public string $redisPrefix) {}
    public static function from(ConfigRepositoryInterface $c): self { $driver = ConfigValidator::string($c, 'queue.driver', 'files'); if (!in_array($driver, ['files', 'redis'], true)) throw new ConfigurationException("Configuration key 'queue.driver' is unsupported."); $self = new self($driver, ConfigValidator::string($c, 'queue.files.path', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'neoframework_jobs'), ConfigValidator::int($c, 'queue.files.default_ttl', 86400), ConfigValidator::int($c, 'queue.files.lock_ttl', 60), ConfigValidator::string($c, 'queue.redis.host'), ConfigValidator::int($c, 'queue.redis.port', 6379), ConfigValidator::string($c, 'queue.redis.password'), ConfigValidator::string($c, 'queue.redis.prefix', 'neoframework:jobs:')); if ($self->driver === 'redis' && $self->redisHost === '') throw new ConfigurationException("Configuration key 'queue.redis.host' is required for the redis driver."); if ($self->redisPort < 1 || $self->defaultTtl < 1 || $self->lockTtl < 1) throw new ConfigurationException('Queue TTLs and port must be positive integers.'); return $self; }
}
