<?php

declare(strict_types=1);

namespace NeoFramework\Core\Commands\Model;

use Ahc\Cli\Input\Command;
use NeoFramework\Core\Commands\ExitCode;
use NeoFramework\Core\Support\ConsoleOutput;

/**
 * Lápide de `model:phpdoc`.
 *
 * Ele reescrevia os `@property` dos models para descrever as colunas que o `__get` mágico
 * do `Db` expunha. A NeoORM 2.0 removeu o `Db`: um model não tem mais propriedade nenhuma,
 * e continuar anotando produziria docblock que o autocomplete usaria e o código desmentiria.
 *
 * Sai com código 1, e não em silêncio, porque a substituição não é automática: `model:types`
 * escreve ARQUIVOS num diretório novo, que precisa entrar no repositório. Um script de build
 * que ainda chame `model:phpdoc` tem que parar para alguém decidir isso.
 */
class Phpdoc extends Command
{
    public function __construct()
    {
        parent::__construct('model:phpdoc', '[REMOVIDO] Veja model:types');

        $this->version('2.0')
            ->option('-d --dry-run', '[REMOVIDO]')
            ->option('--diff', '[REMOVIDO]');
    }

    public function execute(): int
    {
        $output = new ConsoleOutput();

        $output->error('`php neof model:phpdoc` não existe mais na NeoORM 2.');
        $output->write('');
        $output->write('Anotar `@property` descrevia o `__get` mágico do `Db`, que foi removido.');
        $output->write('No lugar, o schema vira código de verdade:');
        $output->write('');
        $output->write('  php neof model:types            gera as classes tipadas dos models');
        $output->write('  php neof model:types --check    falha se o gerado divergir (gate de CI)');
        $output->write('');
        $output->write('Commite o diretório gerado — ele é código-fonte, não artefato de build.');
        $output->write('Configure onde ele fica com PATH_GENERATED e GENERATED_NAMESPACE.');

        return ExitCode::FAILURE;
    }
}
