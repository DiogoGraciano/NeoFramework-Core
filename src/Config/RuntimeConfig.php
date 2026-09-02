<?php
declare(strict_types=1);

namespace NeoFramework\Core\Config;

/** Configuração auditável de serviços que conservam estado entre requests. */
final readonly class RuntimeConfig
{
    /** @param list<class-string> $statefulServices */
    private function __construct(public array $statefulServices) {}

    public static function from(ConfigRepositoryInterface $config): self
    {
        return new self(ConfigValidator::strings($config, 'runtime.stateful_services'));
    }
}
