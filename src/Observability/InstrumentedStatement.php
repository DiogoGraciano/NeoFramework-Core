<?php

declare(strict_types=1);

namespace NeoFramework\Core\Observability;

use NeoFramework\Core\Events\Events;
use NeoFramework\Core\Events\QueryExecuted;
use PDOStatement;

/**
 * Statement que mede cada `execute()`.
 *
 * Instanciada pelo próprio PDO via `ATTR_STATEMENT_CLASS` — uma `PDOStatement`
 * não pode ser envolvida por composição depois de criada, e um decorador
 * separado seria recusado por quem declara `PDOStatement` no type hint.
 *
 * Medir o `prepare` não serviria: ele não toca o banco, e a mesma statement é
 * executada N vezes num laço, que é justamente onde o custo aparece.
 */
final class InstrumentedStatement extends PDOStatement
{
    /** O PDO passa os argumentos declarados em `ATTR_STATEMENT_CLASS`. */
    protected function __construct(private readonly ?string $connection = null)
    {
    }

    public function execute(?array $params = null): bool
    {
        $startedAt = hrtime(true);

        try {
            return parent::execute($params);
        } finally {
            // `finally`: uma consulta que estourou também consumiu tempo de
            // banco, e costuma ser a mais lenta de todas.
            Events::dispatch(new QueryExecuted($this->queryString, (hrtime(true) - $startedAt) / 1_000_000, $this->connection));
        }
    }
}
