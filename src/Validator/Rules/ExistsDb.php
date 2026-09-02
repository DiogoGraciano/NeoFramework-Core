<?php

declare(strict_types=1);

namespace NeoFramework\Core\Validator\Rules;

use Diogodg\Neoorm\Query\Database;
use Diogodg\Neoorm\Query\Expr\ColumnRef;
use Diogodg\Neoorm\Query\Table;
use Respect\Validation\Rules\Core\Simple;
use function Diogodg\Neoorm\Query\eq;

/**
 * O valor já existe na coluna — o oposto de {@see UniqueDb}.
 *
 * ```php
 * #[Rule('existsDb', [Tables::state(), Tables::state()->id])]
 * public int $stateId,
 * ```
 *
 * Sem coluna informada, a regra não adivinha `id`: a chave primária de uma tabela é
 * dado do schema, e passá-la explicitamente é uma linha a mais que elimina a suposição.
 */
class ExistsDb extends Simple
{
    /**
     * @param Table<object> $table
     * @param ColumnRef<mixed> $column
     */
    public function __construct(
        private readonly Table $table,
        private readonly ColumnRef $column,
        private readonly ?Database $database = null,
    ) {
    }

    public function isValid(mixed $input): bool
    {
        $database = $this->database ?? Database::fromConfig();

        return $database->select()->from($this->table)->where(eq($this->column, $input))->count() > 0;
    }
}
