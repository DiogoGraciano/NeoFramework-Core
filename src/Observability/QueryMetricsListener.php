<?php

declare(strict_types=1);

namespace NeoFramework\Core\Observability;

use NeoFramework\Core\Events\QueryExecuted;

/**
 * Contagem e latência de consultas.
 *
 * O SQL **não** entra como label: mesmo preparado ele tem cardinalidade alta
 * demais para uma série temporal. O que vira label é a operação — `select`,
 * `insert`, `update`, `delete` —, que é um conjunto fechado e responde a
 * pergunta que interessa: onde o tempo de banco está indo.
 */
final readonly class QueryMetricsListener
{
    public const EXECUTED = 'db.queries';
    public const DURATION = 'db.queries.duration_ms';

    public function __construct(private MetricsExporterInterface $metrics)
    {
    }

    public function __invoke(object $event): void
    {
        if (!$event instanceof QueryExecuted) return;

        $labels = ['operation' => self::operationOf($event->sql)];
        if ($event->connection !== null) $labels['connection'] = $event->connection;

        $this->metrics->counter(self::EXECUTED, 1, $labels);
        $this->metrics->histogram(self::DURATION, $event->durationMs, $labels);
    }

    /** A primeira palavra da consulta, normalizada; `other` para o que não for CRUD. */
    private static function operationOf(string $sql): string
    {
        if (preg_match('/^\s*(select|insert|update|delete)\b/i', $sql, $match) !== 1) return 'other';

        return strtolower($match[1]);
    }
}
