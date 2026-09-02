<?php

declare(strict_types=1);

namespace NeoFramework\Core\Commands\Db;

use Ahc\Cli\Input\Command;
use Diogodg\Neoorm\Migrations\Command\Check as CheckDrift;
use NeoFramework\Core\Commands\Concerns\UsesMigrations;
use NeoFramework\Core\Commands\ExitCode;
use NeoFramework\Core\Support\ConsoleOutput;
use Throwable;

/**
 * `db:check` — drift em três eixos. É o gate de CI.
 *
 * Só lê: não aplica nada, não escreve nada, e por isso pode rodar em qualquer ambiente,
 * inclusive produção. Sai 0 apenas quando models, snapshot e banco concordam.
 */
class Check extends Command
{
    use UsesMigrations;

    public function __construct()
    {
        parent::__construct('db:check', 'Detect drift between models, snapshot and the live database');

        $this->version('2.0')
            ->option('-s --strict', 'Also fail on tables that no model describes')
            ->option('-p --path [path]', 'Override PATH_MIGRATIONS');
    }

    public function execute(?bool $strict, ?string $path): int
    {
        try {
            $result = (new CheckDrift($this->context($path)))->execute();

            $clean = $strict ? $result->isCleanStrict() : $result->isClean();

            if (!$clean) {
                (new ConsoleOutput())->error('Drift detectado.');
            }

            return $clean ? ExitCode::OK : ExitCode::FAILURE;
        } catch (Throwable $e) {
            return $this->fail($e, (bool) $this->verbosity);
        }
    }
}
