<?php
declare(strict_types=1);
namespace NeoFramework\Core\Config;

final readonly class CorsConfig {
    private function __construct(public bool $enabled, public array $allowedOrigins, public array $allowedMethods, public array $allowedHeaders, public array $exposedHeaders, public int $maxAge, public bool $allowCredentials) {}
    public static function from(ConfigRepositoryInterface $c): self { $self = new self(ConfigValidator::bool($c, 'cors.enabled'), ConfigValidator::strings($c, 'cors.allowed_origins', ['*']), ConfigValidator::strings($c, 'cors.allowed_methods', ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH']), ConfigValidator::strings($c, 'cors.allowed_headers', ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept', 'Origin']), ConfigValidator::strings($c, 'cors.exposed_headers'), ConfigValidator::int($c, 'cors.max_age', 86400), ConfigValidator::bool($c, 'cors.allow_credentials')); if ($self->maxAge < 0) throw new ConfigurationException("Configuration key 'cors.max_age' must not be negative."); return $self; }
    /** @return array<string,mixed> */ public function middleware(): array { return ['allowed_origins' => $this->allowedOrigins, 'allowed_methods' => $this->allowedMethods, 'allowed_headers' => $this->allowedHeaders, 'exposed_headers' => $this->exposedHeaders, 'max_age' => $this->maxAge, 'allow_credentials' => $this->allowCredentials]; }
}
