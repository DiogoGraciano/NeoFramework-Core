<?php

declare(strict_types=1);

namespace NeoFramework\Core\Commands\Migration;

use Ahc\Cli\Input\Command;
use Diogodg\Neoorm\Migrations\Command\Up as ApplyMigrations;
use NeoFramework\Core\Commands\Concerns\UsesMigrations;
use NeoFramework\Core\Commands\ExitCode;
use NeoFramework\Core\Events\Events;
use NeoFramework\Core\Events\MigrationApplied;
use NeoFramework\Core\Support\ConsoleOutput;
use Throwable;

/**
 * `migration:up` — aplica as migrações pendentes.
 *
 * NÃO faz seed: schema e dados são coisas separadas, e misturá-los era o que fazia o
 * `migrate` antigo semear no meio do loop de criação, antes de as foreign keys existirem.
 */
class Up extends Command
{
    use UsesMigrations;

    public function __construct()
    {
        parent::__construct('migration:up', 'Apply pending migrations');

        $this->version('2.0')
            ->option('-t --to [tag]', 'Apply up to this migration, inclusive')
            ->option('-s --step [n]', 'Apply at most this many migrations', 'intval')
            ->option('-d --dry-run', 'Show what would be applied without applying it')
            ->option('-p --path [path]', 'Override PATH_MIGRATIONS');
    }

    public function execute(?string $to, ?int $step, ?bool $dryRun, ?string $path): int
    {
        try {
            $result = (new ApplyMigrations($this->context($path)))->execute($to, $step, (bool) $dryRun);

            // O dispatch fica aqui e não na NeoORM: o evento é do framework, e a
            // biblioteca de migração não deve conhecer o dispatcher dele.
            foreach ($result->applied as $migration) {
                Events::dispatch(new MigrationApplied($migration->tag, count($migration->statements), (bool) $dryRun, $migration->resumed));
            }

            if ($dryRun) {
                $output = new ConsoleOutput();

                foreach ($result->applied as $migration) {
                    foreach ($migration->statements as $statement) {
                        $output->write('  ' . $statement);
                    }
                }
            }

            return ExitCode::OK;
        } catch (Throwable $e) {
            return $this->fail($e, (bool) $this->verbosity);
        }
    }
}
