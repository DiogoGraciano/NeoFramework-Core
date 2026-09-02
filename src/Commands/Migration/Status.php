<?php

declare(strict_types=1);

namespace NeoFramework\Core\Commands\Migration;

use Ahc\Cli\Input\Command;
use Diogodg\Neoorm\Migrations\Command\Status as MigrationStatus;
use NeoFramework\Core\Commands\Concerns\UsesMigrations;
use NeoFramework\Core\Commands\ExitCode;
use Throwable;

/**
 * `migration:status` — o estado das migrações, sem aplicar nada.
 *
 * Sai 1 quando há problema — arquivo alterado depois de aplicado, `.sql` ausente, tag que o
 * repositório não conhece —, mas NÃO quando há apenas migrações pendentes: pendência é o
 * estado normal de quem acabou de fazer `git pull`, e um comando de diagnóstico que falha
 * nela vira ruído que se aprende a ignorar.
 */
class Status extends Command
{
    use UsesMigrations;

    public function __construct()
    {
        parent::__construct('migration:status', 'Show which migrations are applied, pending or inconsistent');

        $this->version('2.0')->option('-p --path [path]', 'Override PATH_MIGRATIONS');
    }

    public function execute(?string $path): int
    {
        try {
            $status = (new MigrationStatus($this->context($path)))->execute();

            $inconsistent = $status->problems() !== []
                || $status->unknownTags !== []
                || $status->orphanFiles !== [];

            return $inconsistent ? ExitCode::FAILURE : ExitCode::OK;
        } catch (Throwable $e) {
            return $this->fail($e, (bool) $this->verbosity);
        }
    }
}
