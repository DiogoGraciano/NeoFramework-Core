<?php

declare(strict_types=1);

namespace NeoFramework\Core\Commands\Db;

use Ahc\Cli\Input\Command;
use Diogodg\Neoorm\Migrations\Command\Pull as PullSchema;
use NeoFramework\Core\Commands\Concerns\UsesMigrations;
use NeoFramework\Core\Commands\ExitCode;
use Throwable;

/**
 * `db:pull` — lê o banco e escreve o que encontrou como snapshot.
 *
 * Serve para inspecionar o que o servidor realmente tem, num formato comparável com o que o
 * repositório descreve — o passo zero de diagnosticar drift. Não mexe no journal nem escreve
 * `.sql`: sobrescrever um snapshot de `meta/` faria o próximo `generate` diffar contra o banco
 * em vez de contra o histórico.
 */
class Pull extends Command
{
    use UsesMigrations;

    public function __construct()
    {
        parent::__construct('db:pull', 'Read the live database into a snapshot file');

        $this->version('2.0')
            ->option('-o --out [file]', 'Where to write the snapshot; prints to stdout when omitted')
            ->option('-d --only-declared', 'Read only the tables the models describe');
    }

    public function execute(?string $out, ?bool $onlyDeclared): int
    {
        try {
            $result = (new PullSchema($this->context()))->execute($out, (bool) $onlyDeclared);

            if ($out === null) {
                echo $result['json'];
            }

            return ExitCode::OK;
        } catch (Throwable $e) {
            return $this->fail($e, (bool) $this->verbosity);
        }
    }
}
