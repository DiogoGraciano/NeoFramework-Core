<?php
declare(strict_types=1);
namespace NeoFramework\Core\Config;

final readonly class SecurityHeadersConfig {
    private function __construct(public bool $enabled, public bool $viteCspRelax, public array $headers) {}
    public static function from(ConfigRepositoryInterface $c): self { $headers = $c->get('security_headers.headers', []); if (!is_array($headers) || array_filter($headers, static fn (mixed $v): bool => !is_string($v)) !== []) throw new ConfigurationException("Configuration key 'security_headers.headers' must be a map of strings."); return new self(ConfigValidator::bool($c, 'security_headers.enabled', true), ConfigValidator::bool($c, 'security_headers.vite_csp_relax', true), $headers); }
}
