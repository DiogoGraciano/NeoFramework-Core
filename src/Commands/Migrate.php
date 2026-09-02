<?php

declare(strict_types=1);

namespace NeoFramework\Core\Commands;

use Ahc\Cli\Input\Command;
use NeoFramework\Core\Commands\Concerns\UsesMigrations;
use NeoFramework\Core\Support\ConsoleOutput;

/**
 * `migrate` — lápide.
 *
 * O comando antigo fazia quatro coisas ao mesmo tempo, sem nomeá-las: recriava o banco,
 * convergia o schema, semeava dados e reescrevia docblocks. Isso não é um comando, é um
 * roteiro — e um roteiro que ninguém podia executar pela metade, nem em ordem diferente, nem
 * inspecionar antes.
 *
 * Some em vez de virar alias porque `migrate` não tem equivalente único. Um alias para
 * `db:push` estragaria o dia de quem esperava versionamento; um para `migration:up` não
 * criaria o banco nem semearia, e a falha seria confusa. Imprimir o mapa e sair com código 1
 * é a única resposta que não escolhe errado pelo usuário — e o código 1 garante que um script
 * de deploy que ainda chame `php neof migrate` PARE, em vez de seguir achando que migrou.
 */
class Migrate extends Command
{
    use UsesMigrations;

    public function __construct()
    {
        parent::__construct('migrate', '[REMOVIDO] Veja migration:* e db:*');

        $this->version('2.0')->option('-r --recreate', '[REMOVIDO]');
    }

    public function execute(): int
    {
        $output = new ConsoleOutput();

        $output->error('`php neof migrate` não existe mais na NeoORM 2.');
        $output->write('');
        $output->write('Os comandos agora dizem o que mexem: `migration:*` mexe em arquivos do');
        $output->write('repositório, `db:*` mexe no banco vivo.');
        $output->write('');
        $output->write('  migration:generate [nome]   escreve uma migração (não abre conexão)');
        $output->write('  migration:up                aplica as migrações pendentes');
        $output->write('  migration:status            mostra o que está aplicado e o que falta');
        $output->write('');
        $output->write('  db:push                     converge o banco direto, sem gerar migração (dev)');
        $output->write('  db:pull                     lê o banco para um snapshot');
        $output->write('  db:check                    detecta drift em três eixos (gate de CI)');
        $output->write('  db:seed                     popula o banco');
        $output->write('  db:reset [--seed]           apaga, recria e aplica tudo (dev)');
        $output->write('');
        $output->write('  model:types                 gera as classes tipadas dos models');
        $output->write('');
        $output->write('O que você provavelmente quer:');
        $output->write('');
        $output->write('  em desenvolvimento     php neof db:reset --seed');
        $output->write('  antes de commitar      php neof migration:generate <nome>');
        $output->write('  em deploy              php neof migration:up');
        $output->write('  o antigo --recreate    php neof db:reset');
        $output->write('');
        $output->write('Veja UPGRADE.md para o passo a passo.');

        return ExitCode::FAILURE;
    }
}
