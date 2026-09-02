<?php

declare(strict_types=1);

namespace NeoFramework\Core\Commands\Db;

use Ahc\Cli\Input\Command;
use Diogodg\Neoorm\Codegen\FileSystemWriter;
use Diogodg\Neoorm\Codegen\Generator;
use Diogodg\Neoorm\Config;
use Diogodg\Neoorm\Migrations\Command\Push as PushSchema;
use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Schema\ModelSchemaLoader;
use NeoFramework\Core\Commands\Concerns\UsesMigrations;
use NeoFramework\Core\Commands\ExitCode;
use NeoFramework\Core\Support\ConsoleOutput;
use Throwable;

/**
 * `db:push` — converge o banco com os models direto, sem escrever migração.
 *
 * Para a iteração de desenvolvimento em que se muda um model dez vezes por hora e não se quer
 * dez migrações no histórico. Recusa em produção, porque não deixa rastro: sem arquivo e sem
 * linha na tabela de controle, não há como reproduzir o estado resultante em outro ambiente.
 */
class Push extends Command
{
    use UsesMigrations;

    public function __construct()
    {
        parent::__construct('db:push', 'Converge the database with the models directly, writing no migration');

        $this->version('2.0')
            ->option('-f --force', 'Allow changes that discard data')
            ->option('-d --dry-run', 'Show what would be applied without applying it')
            ->option('--no-types', 'Skip regenerating the typed model classes');
    }

    public function execute(?bool $force, ?bool $dryRun, ?bool $noTypes): int
    {
        try {
            $context = $this->context();

            (new PushSchema($context))->execute((bool) $dryRun, (bool) $force);

            // As classes tipadas são regeradas por padrão, e desligável. Elas saem do fluxo
            // de migração — no sistema antigo o `migrate` reescrevia docblocks sem nome e sem
            // como recusar — mas continuam automáticas em desenvolvimento porque schema
            // aplicado e tipos velhos é autocompletar mentindo, e ninguém lembra de rodar um
            // comando para isso.
            if (!$dryRun && !$noTypes && !$context->isProduction()) {
                $loader = new ModelSchemaLoader(Config::getPathModel(), Config::getModelNamespace());
                $files = (new Generator(Config::getGeneratedNamespace()))->generate($loader->load());
                $report = (new FileSystemWriter(Config::getPathGenerated()))->write($files);

                $context->output->write($report->summary());
            }

            return ExitCode::OK;
        } catch (MigrationException $e) {
            (new ConsoleOutput())->error($e->getMessage());

            return ExitCode::NEEDS_DECISION;
        } catch (Throwable $e) {
            return $this->fail($e, (bool) $this->verbosity);
        }
    }
}
