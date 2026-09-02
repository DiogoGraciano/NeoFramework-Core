<?php
declare(strict_types=1);
namespace NeoFramework\Core\Config;

final readonly class AppConfig {
    private function __construct(public string $environment, public string $url) {}
    public static function from(ConfigRepositoryInterface $config): self {
        $environment = ConfigValidator::string($config, 'app.environment', 'dev');
        if (!in_array($environment, ['dev', 'test', 'prod'], true)) throw new ConfigurationException("Configuration key 'app.environment' must be dev, test, or prod.");
        return new self($environment, ConfigValidator::string($config, 'app.url'));
    }
    public function isProduction(): bool { return $this->environment === 'prod'; }
}
