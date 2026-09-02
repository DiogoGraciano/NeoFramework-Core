<?php
declare(strict_types=1);
namespace NeoFramework\Core\Config;

final readonly class CryptoConfig {
    private function __construct(public string $encryptionKey, public string $authenticationKey) {}
    public static function from(ConfigRepositoryInterface $c): self { $self = new self(ConfigValidator::string($c, 'crypto.encryption_key'), ConfigValidator::string($c, 'crypto.authentication_key')); if (($self->encryptionKey === '') !== ($self->authenticationKey === '')) throw new ConfigurationException('Both crypto keys must be configured together.'); if ($self->encryptionKey !== '') { $self->decodedEncryptionKey(); $self->decodedAuthenticationKey(); } return $self; }
    public function decodedEncryptionKey(): string { return self::decode($this->encryptionKey, 'crypto.encryption_key'); }
    public function decodedAuthenticationKey(): string { return self::decode($this->authenticationKey, 'crypto.authentication_key'); }
    private static function decode(string $key, string $name): string { $decoded = base64_decode($key, true); if ($decoded === false || $decoded === '') throw new ConfigurationException("Configuration key '{$name}' must be a non-empty base64 key."); return $decoded; }
}
