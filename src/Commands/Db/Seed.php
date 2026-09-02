<?php

declare(strict_types=1);

namespace NeoFramework\Core\Commands\Db;

use Ahc\Cli\Input\Command;
use Diogodg\Neoorm\Migrations\Command\Seed as RunSeeds;
use NeoFramework\Core\Commands\Concerns\UsesMigrations;
use NeoFramework\Core\Commands\ExitCode;
use Throwable;

/**
 * `db:seed` — popula o banco, em ordem topológica de foreign key, dentro de uma transação.
 *
 * Separado de `migration:up` de propósito: no sistema antigo o seed rodava no MEIO do loop de
 * criação de tabelas, antes de as foreign keys existirem, e em ordem alfabética de arquivo.
 */
class Seed extends Command
{
    use UsesMigrations;

    public function __construct()
    {
        parent::__construct('db:seed', 'Run the seeders in PATH_SEEDS');

        $this->version('2.0')->argument('[tables...]', 'Seed only these tables');
    }

    public function execute(?array $tables): int
    {
        try {
            (new RunSeeds($this->context()))->execute($tables ?: null);

            return ExitCode::OK;
        } catch (Throwable $e) {
            return $this->fail($e, (bool) $this->verbosity);
        }
    }
}
