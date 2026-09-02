<?php
declare(strict_types=1);
namespace NeoFramework\Core\Config;

final readonly class LoggingConfig
{
    private function __construct(
        public string $channel,
        public string $level,
        /** 'json' ou 'line' */
        public string $format,
        /** 'file', 'stderr' ou 'stdout' */
        public string $stream,
        public string $path,
    ) {}

    public static function from(ConfigRepositoryInterface $c): self
    {
        $self = new self(
            ConfigValidator::string($c, 'logging.channel', 'system'),
            strtolower(ConfigValidator::string($c, 'logging.level', 'debug')),
            strtolower(ConfigValidator::string($c, 'logging.format', 'line')),
            strtolower(ConfigValidator::string($c, 'logging.stream', 'file')),
            ConfigValidator::string($c, 'logging.path', 'Logs/system.log'),
        );

        if (!in_array($self->format, ['json', 'line'], true)) {
            throw new ConfigurationException("Configuration key 'logging.format' must be 'json' or 'line'.");
        }
        if (!in_array($self->stream, ['file', 'stderr', 'stdout'], true)) {
            throw new ConfigurationException("Configuration key 'logging.stream' must be 'file', 'stderr' or 'stdout'.");
        }
        if (!in_array($self->level, ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'], true)) {
            throw new ConfigurationException("Configuration key 'logging.level' is not a valid PSR-3 level.");
        }

        return $self;
    }
}
