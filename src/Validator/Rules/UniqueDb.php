<?php

declare(strict_types=1);

namespace NeoFramework\Core\Validator\Rules;

use Diogodg\Neoorm\Query\Database;
use Diogodg\Neoorm\Query\Expr\ColumnRef;
use Diogodg\Neoorm\Query\Table;
use Respect\Validation\Rules\Core\Simple;
use function Diogodg\Neoorm\Query\eq;

/**
 * O valor ainda não existe na coluna.
 *
 * ```php
 * #[Rule('uniqueDb', [Tables::users(), Tables::users()->email])]
 * public string $email,
 * ```
 *
 * Recebe a tabela GERADA e a referência de coluna, e não mais um `Model` e o nome da
 * coluna em string. Duas coisas mudam com isso: uma coluna inexistente vira erro de
 * análise estática em vez de exceção em runtime, e a regra consulta o banco em vez de
 * instanciar um model — que na 1.x carregava a linha inteira só para olhar um campo.
 */
class UniqueDb extends Simple
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

        return $database->select()->from($this->table)->where(eq($this->column, $input))->count() === 0;
    }
}
