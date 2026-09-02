<?php

declare(strict_types=1);

namespace NeoFramework\Core\Observability;

/**
 * Descarta tudo. É o padrão.
 *
 * Observabilidade desligada não pode alterar o comportamento da aplicação, nem
 * custar mais que uma chamada de método vazia.
 */
final class NullMetricsExporter implements MetricsExporterInterface
{
    public function counter(string $name, int $value = 1, array $labels = []): void
    {
    }

    public function gauge(string $name, float $value, array $labels = []): void
    {
    }

    public function histogram(string $name, float $value, array $labels = []): void
    {
    }
}
