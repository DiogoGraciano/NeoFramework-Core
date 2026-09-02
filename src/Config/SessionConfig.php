<?php
declare(strict_types=1);
namespace NeoFramework\Core\Config;

final readonly class SessionConfig {
    private function __construct(public string $sameSite, public bool $httpOnly, public ?bool $secure) {}
    public static function from(ConfigRepositoryInterface $c): self { $value = ConfigValidator::string($c, 'session.same_site', 'Lax'); if (!in_array($value, ['Lax', 'Strict', 'None'], true)) throw new ConfigurationException("Configuration key 'session.same_site' must be Lax, Strict, or None."); $secure = $c->get('session.secure'); if ($secure !== null && !is_bool($secure)) throw new ConfigurationException("Configuration key 'session.secure' must be a boolean or null."); return new self($value, ConfigValidator::bool($c, 'session.http_only', true), $secure); }
}
