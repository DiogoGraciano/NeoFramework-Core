<?php

declare(strict_types=1);

namespace NeoFramework\Core\Events;

/**
 * Uma consulta terminou, com o tempo que levou.
 *
 * `sql` é a consulta **preparada**, sem os valores ligados: o texto com os
 * parâmetros interpolados vazaria dado pessoal para dentro de log e métrica, e
 * daria cardinalidade infinita a qualquer agregação por consulta.
 */
final readonly class QueryExecuted
{
    public function __construct(
        public string $sql,
        public float $durationMs,
        public ?string $connection = null,
    ) {
    }
}
