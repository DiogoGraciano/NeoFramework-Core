<?php

declare(strict_types=1);

namespace NeoFramework\Core\Observability;

/** Guarda o que foi emitido. Para testes e para inspeção em desenvolvimento. */
final class InMemoryMetricsExporter implements MetricsExporterInterface
{
    /** @var list<array{type:string,name:string,value:float,labels:array<string,string>}> */
    private array $samples = [];

    public function counter(string $name, int $value = 1, array $labels = []): void
    {
        $this->record('counter', $name, (float) $value, $labels);
    }

    public function gauge(string $name, float $value, array $labels = []): void
    {
        $this->record('gauge', $name, $value, $labels);
    }

    public function histogram(string $name, float $value, array $labels = []): void
    {
        $this->record('histogram', $name, $value, $labels);
    }

    /** @return list<array{type:string,name:string,value:float,labels:array<string,string>}> */
    public function samples(?string $name = null): array
    {
        return $name === null ? $this->samples : array_values(array_filter($this->samples, static fn (array $s): bool => $s['name'] === $name));
    }

    public function reset(): void
    {
        $this->samples = [];
    }

    /** @param array<string,string> $labels */
    private function record(string $type, string $name, float $value, array $labels): void
    {
        $this->samples[] = ['type' => $type, 'name' => $name, 'value' => $value, 'labels' => $labels];
    }
}
