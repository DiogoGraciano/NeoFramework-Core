<?php
declare(strict_types=1);

namespace NeoFramework\Core\Config;

/** Validates the built-in typed domains before the cache is published. */
final class ConfigValidator
{
    /** @param array<string,mixed> $items */
    public static function validate(array $items): void
    {
        $repository = new ConfigRepository(sys_get_temp_dir(), $items, false);
        AppConfig::from($repository);
        HttpConfig::from($repository);
        CorsConfig::from($repository);
        SecurityHeadersConfig::from($repository);
        SessionConfig::from($repository);
        CacheConfig::from($repository);
        LoggingConfig::from($repository);
        QueueConfig::from($repository);
        DatabaseConfig::from($repository);
        TemplateConfig::from($repository);
        ViteConfig::from($repository);
        MailConfig::from($repository);
        CryptoConfig::from($repository);
        StorageConfig::from($repository);
        RuntimeConfig::from($repository);
        RateLimitConfig::from($repository);
        AuthConfig::from($repository);
    }

    public static function string(ConfigRepositoryInterface $config, string $key, string $default = ''): string
    {
        $value = $config->get($key, $default);
        if (!is_string($value)) {
            throw new ConfigurationException("Configuration key '{$key}' must be a string.");
        }
        return $value;
    }

    public static function bool(ConfigRepositoryInterface $config, string $key, bool $default = false): bool
    {
        $value = $config->get($key, $default);
        if (!is_bool($value)) {
            throw new ConfigurationException("Configuration key '{$key}' must be a boolean.");
        }
        return $value;
    }

    public static function int(ConfigRepositoryInterface $config, string $key, int $default = 0): int
    {
        $value = $config->get($key, $default);
        if (!is_int($value)) {
            throw new ConfigurationException("Configuration key '{$key}' must be an integer.");
        }
        return $value;
    }

    /** @return list<string> */
    public static function strings(ConfigRepositoryInterface $config, string $key, array $default = []): array
    {
        $value = $config->get($key, $default);
        if (!is_array($value) || array_filter($value, static fn (mixed $item): bool => !is_string($item)) !== []) {
            throw new ConfigurationException("Configuration key '{$key}' must be a list of strings.");
        }
        return array_values($value);
    }
}
