<?php

declare(strict_types=1);

namespace NeoFramework\Core\Observability;

/**
 * Destino das métricas da aplicação.
 *
 * O Core só emite; agregar, reter e expor é do adapter. Os três tipos abaixo
 * cobrem o que Prometheus, StatsD e OpenTelemetry entendem em comum, e nada
 * além disso — um contrato que só um backend implementa não é um contrato.
 *
 * **Labels são cardinalidade.** Cada combinação de valores vira uma série
 * temporal no backend. Rota, método e status são conjuntos fechados e podem ser
 * label; id de usuário, path resolvido e mensagem de erro não podem — é assim
 * que um backend de métricas cai.
 */
interface MetricsExporterInterface
{
    /** Valor que só cresce: requisições, erros, jobs processados. */
    public function counter(string $name, int $value = 1, array $labels = []): void;

    /** Valor instantâneo: conexões abertas, tamanho de fila, memória. */
    public function gauge(string $name, float $value, array $labels = []): void;

    /** Distribuição: latência, tamanho de payload, duração de query. */
    public function histogram(string $name, float $value, array $labels = []): void;
}
