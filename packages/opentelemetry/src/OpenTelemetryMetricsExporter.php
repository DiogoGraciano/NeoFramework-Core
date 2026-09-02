<?php

declare(strict_types=1);

namespace NeoFramework\OpenTelemetry;

use NeoFramework\Core\Observability\MetricsExporterInterface;
use OpenTelemetry\API\Metrics\MeterInterface;

/**
 * Adapter do `MetricsExporterInterface` do Core para o OpenTelemetry.
 *
 * Os instrumentos são criados uma vez por nome e reaproveitados: o OTel espera
 * um instrumento por métrica, e recriá-lo a cada chamada produz séries
 * duplicadas no backend além de desperdiçar alocação em todo request.
 */
final class OpenTelemetryMetricsExporter implements MetricsExporterInterface
{
    /** @var array<string,object> */
    private array $instruments = [];

    public function __construct(private readonly MeterInterface $meter)
    {
    }

    public function counter(string $name, int $value = 1, array $labels = []): void
    {
        $this->instruments['c:' . $name] ??= $this->meter->createCounter($name);
        $this->instruments['c:' . $name]->add($value, $labels);
    }

    public function gauge(string $name, float $value, array $labels = []): void
    {
        // O OTel só tem gauge assíncrono na API estável; um UpDownCounter com o
        // valor absoluto seria errado, porque ele soma. `createGauge` existe a
        // partir da 1.1 e é o instrumento correto para valor instantâneo.
        $this->instruments['g:' . $name] ??= $this->meter->createGauge($name);
        $this->instruments['g:' . $name]->record($value, $labels);
    }

    public function histogram(string $name, float $value, array $labels = []): void
    {
        $this->instruments['h:' . $name] ??= $this->meter->createHistogram($name);
        $this->instruments['h:' . $name]->record($value, $labels);
    }
}
