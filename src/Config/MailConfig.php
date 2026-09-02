<?php
declare(strict_types=1);
namespace NeoFramework\Core\Config;

final readonly class MailConfig {
    private function __construct(public string $host, public int $port, public string $username, public string $password, public string $encryption, public string $fromAddress, public string $fromName) {}
    public static function from(ConfigRepositoryInterface $c): self { $self = new self(ConfigValidator::string($c, 'mail.host'), ConfigValidator::int($c, 'mail.port'), ConfigValidator::string($c, 'mail.username'), ConfigValidator::string($c, 'mail.password'), ConfigValidator::string($c, 'mail.encryption'), ConfigValidator::string($c, 'mail.from_address'), ConfigValidator::string($c, 'mail.from_name', 'Site')); if (($self->host === '') !== ($self->port === 0) || $self->port < 0) throw new ConfigurationException('Mail host and port must be configured together.'); return $self; }
    public function isConfigured(): bool { return $this->host !== '' && $this->port > 0; }
}
