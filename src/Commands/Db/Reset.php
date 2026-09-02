<?php

declare(strict_types=1);

namespace NeoFramework\Core\Commands\Db;

use Ahc\Cli\Input\Command;
use Diogodg\Neoorm\Migrations\Command\Reset as ResetDatabase;
use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use NeoFramework\Core\Commands\Concerns\UsesMigrations;
use NeoFramework\Core\Commands\ExitCode;
use NeoFramework\Core\Support\ConsoleOutput;
use Throwable;

/**
 * `db:reset` — derruba o banco, recria, e aplica todas as migrações.
 *
 * Como não há migração `down`, é isto que se usa em desenvolvimento quando o banco entra num
 * estado que não vale destrinchar. Num terminal, exige digitar o nome do banco: `--force` é
 * exatamente o que se cola de um histórico de shell sem ler.
 */
class Reset extends Command
{
    use UsesMigrations;

    public function __construct()
    {
        parent::__construct('db:reset', 'Drop and recreate the database, then apply every migration');

        $this->version('2.0')
            ->option('-f --force', 'Skip the interactive confirmation')
            ->option('-s --seed', 'Run db:seed afterwards');
    }

    public function execute(?bool $force, ?bool $seed): int
    {
        $context = $this->context();
        $output = new ConsoleOutput();

        try {
            $confirmation = null;

            if (!$force) {
                $database = $context->database->database;

                $output->warning("Isto vai APAGAR o banco '{$database}' e recriá-lo do zero.");
                $confirmation = $this->io()->prompt("Digite o nome do banco para confirmar", '');
            }

            (new ResetDatabase($context))->execute((bool) $force, (bool) $seed, $confirmation);

            return ExitCode::OK;
        } catch (MigrationException $e) {
            $output->error($e->getMessage());

            return ExitCode::NEEDS_DECISION;
        } catch (Throwable $e) {
            return $this->fail($e, (bool) $this->verbosity);
        }
    }
}
