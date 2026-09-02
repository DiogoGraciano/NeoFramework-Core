<?php

declare(strict_types=1);

namespace NeoFramework\Core\Commands\Migration;

use Ahc\Cli\Input\Command;
use Diogodg\Neoorm\Migrations\Command\Generate as GenerateMigration;
use Diogodg\Neoorm\Migrations\Diff\MapRenameResolver;
use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use NeoFramework\Core\Commands\Concerns\UsesMigrations;
use NeoFramework\Core\Commands\ExitCode;
use NeoFramework\Core\Support\ConsoleOutput;
use Throwable;

/**
 * `migration:generate` — escreve uma migração a partir da diferença entre os models e o
 * último snapshot.
 *
 * Não abre conexão com o banco. É o que permite gerar e revisar migração num CI sem serviço
 * de banco, e revisar o SQL no pull request antes de ele tocar em ambiente nenhum.
 */
class Generate extends Command
{
    use UsesMigrations;

    public function __construct()
    {
        parent::__construct('migration:generate', 'Write a migration from the difference between models and the last snapshot');

        $this->version('2.0')
            ->argument('[name]', 'Free name for the migration; derived from the operations when omitted')
            ->option('-e --empty', 'Write an empty migration for hand-written SQL')
            ->option('-r --rename [pairs...]', 'Resolve a rename: old:new, or table.old:new')
            ->option('-a --allow-destructive', 'Allow DROP TABLE and DROP COLUMN')
            ->option('-d --dry-run', 'Show what would be written without writing it')
            ->option('-p --path [path]', 'Override PATH_MIGRATIONS');
    }

    public function execute(
        ?string $name,
        ?bool $empty,
        ?array $rename,
        ?bool $allowDestructive,
        ?bool $dryRun,
        ?string $path,
    ): int {
        try {
            $result = (new GenerateMigration($this->context($path)))->execute(
                $name,
                empty: (bool) $empty,
                dryRun: (bool) $dryRun,
                // Sem `--rename`, o resolvedor é nulo — e é ele que faz o comando PERGUNTAR
                // em vez de adivinhar. Com pares dados, a decisão já veio.
                renames: $rename ? new MapRenameResolver($rename) : null,
                allowDestructive: (bool) $allowDestructive,
            );

            $output = new ConsoleOutput();

            foreach ($result->describe() as $operation) {
                $output->write('  ' . $operation);
            }

            foreach ($result->warnings as $warning) {
                $output->warning('  ' . $warning);
            }

            if ($dryRun && !$result->nothingToDo()) {
                $output->write('');
                $output->write($result->sql);
            }

            return ExitCode::OK;
        } catch (MigrationException $e) {
            // Ambiguidade e destruição não são erro do sistema: são decisões que faltam.
            // O código 2 separa "preciso que você escolha" de "quebrou", o que importa para
            // um script de CI saber se pode continuar.
            (new ConsoleOutput())->error($e->getMessage());

            return ExitCode::NEEDS_DECISION;
        } catch (Throwable $e) {
            return $this->fail($e, (bool) $this->verbosity);
        }
    }
}
