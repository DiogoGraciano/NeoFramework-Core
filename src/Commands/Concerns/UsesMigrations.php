<?php

declare(strict_types=1);

namespace NeoFramework\Core\Commands\Concerns;

use Diogodg\Neoorm\Migrations\Command\MigrationContext;
use NeoFramework\Core\Commands\ExitCode;
use NeoFramework\Core\Support\ConsoleOutput;
use Throwable;

/**
 * A fiação comum dos comandos de migração.
 *
 * Todo comando aqui é uma casca: lê as opções, monta o contexto, chama UM método da NeoORM e
 * formata o DTO que volta. A lógica mora na biblioteca, que é testável sem servidor — se
 * algum destes arquivos crescer, é sinal de que uma regra escapou para um lugar onde só dá
 * para exercitá-la com banco de pé e terminal.
 *
 * Os códigos de saída vivem em `ExitCode`, e não aqui: constante de trait só é legível
 * através da classe que usa o trait, o que tornaria `UsesMigrations::EXIT_OK` inacessível a
 * quem quisesse comparar contra ele — um teste, ou um script que chame o comando.
 */
trait UsesMigrations
{
    protected function context(?string $path = null): MigrationContext
    {
        return MigrationContext::fromConfig(new ConsoleOutput(), $path);
    }

    /**
     * Reporta a falha e devolve o código de saída.
     *
     * Sem `getTraceAsString()` no caminho normal: as exceções desta biblioteca têm mensagens
     * escritas para serem lidas — dizem o que aconteceu, por que, e o que fazer — e um stack
     * trace de quarenta linhas por cima delas só serve para esconder isso. Quem quer o trace
     * usa `-v`.
     */
    protected function fail(Throwable $e, bool $verbose = false): int
    {
        $output = new ConsoleOutput();

        $output->error($e->getMessage());

        if ($verbose) {
            $output->error($e->getTraceAsString());
        }

        return ExitCode::FAILURE;
    }
}
