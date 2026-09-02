<?php

declare(strict_types=1);

namespace NeoFramework\Core\Observability;

use NeoFramework\Core\Events\JobFailed;
use NeoFramework\Core\Events\JobProcessed;

/**
 * Traduz o ciclo de um job em métricas.
 *
 * Nome do job e da fila são conjuntos fechados — classe e configuração —, então
 * servem como label. O id do job não serve: seria uma série temporal por
 * execução.
 */
final readonly class QueueMetricsListener
{
    public const PROCESSED = 'queue.jobs.processed';
    public const DURATION = 'queue.jobs.duration_ms';

    public function __construct(private MetricsExporterInterface $metrics)
    {
    }

    public function __invoke(object $event): void
    {
        match (true) {
            $event instanceof JobProcessed => $this->record($event->jobClass(), $event->queue, 'completed', $event->durationMs),
            // Uma falha que ainda vai tentar de novo não é a mesma coisa que uma
            // que desistiu: separá-las é o que permite alertar só na segunda.
            $event instanceof JobFailed => $this->record($event->jobClass(), $event->queue, $event->willRetry ? 'retrying' : 'failed', $event->durationMs),
            default => null,
        };
    }

    private function record(string $job, string $queue, string $status, float $durationMs): void
    {
        $labels = ['job' => $job, 'queue' => $queue, 'status' => $status];

        $this->metrics->counter(self::PROCESSED, 1, $labels);
        $this->metrics->histogram(self::DURATION, $durationMs, $labels);
    }
}
