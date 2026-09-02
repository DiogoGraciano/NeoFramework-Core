<?php
declare(strict_types=1);
namespace NeoFramework\Core\Config;

final readonly class HttpConfig {
    private function __construct(public string $basePath, public array $trustedProxies, public array $trustedHosts) {}
    public static function from(ConfigRepositoryInterface $config): self {
        $basePath = $config->get('http.base_path');
        if ($basePath !== null && !is_string($basePath)) throw new ConfigurationException("Configuration key 'http.base_path' must be a string or null.");
        return new self($basePath ?? '', ConfigValidator::strings($config, 'http.trusted_proxies'), ConfigValidator::strings($config, 'http.trusted_hosts'));
    }
}
