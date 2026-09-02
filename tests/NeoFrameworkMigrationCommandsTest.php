<?php

declare(strict_types=1);

namespace Tests;

use Ahc\Cli\Input\Command;
use NeoFramework\Core\Commands\Db\Check;
use NeoFramework\Core\Commands\Db\Pull;
use NeoFramework\Core\Commands\Db\Push;
use NeoFramework\Core\Commands\Db\Reset;
use NeoFramework\Core\Commands\Db\Seed;
use NeoFramework\Core\Commands\ExitCode;
use NeoFramework\Core\Commands\Migrate;
use NeoFramework\Core\Commands\Migration\Generate;
use NeoFramework\Core\Commands\Migration\Status;
use NeoFramework\Core\Commands\Migration\Up;
use NeoFramework\Core\Commands\Model\Phpdoc;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Os comandos de migração como CLI: nomes, opções e o código de saída da lápide.
 *
 * O que NÃO se testa aqui é o comportamento das migrações — ele mora na NeoORM e é testado
 * lá, sem banco, com mais casos do que caberia num teste de CLI. Estes comandos são cascas, e
 * o que se afirma é que a casca está no lugar certo: um comando registrado com o nome errado,
 * ou uma opção que o parser recusa, quebra o deploy de quem atualizar sem que nenhum teste da
 * biblioteca perceba.
 */
class NeoFrameworkMigrationCommandsTest extends TestCase
{
    /**
     * @return iterable<string,array{class-string<Command>,string}>
     */
    public static function commands(): iterable
    {
        // `migration:*` mexe em arquivos do repositório; `db:*` mexe no banco vivo.
        yield 'migration:generate' => [Generate::class, 'migration:generate'];
        yield 'migration:up' => [Up::class, 'migration:up'];
        yield 'migration:status' => [Status::class, 'migration:status'];
        yield 'db:push' => [Push::class, 'db:push'];
        yield 'db:pull' => [Pull::class, 'db:pull'];
        yield 'db:check' => [Check::class, 'db:check'];
        yield 'db:seed' => [Seed::class, 'db:seed'];
        yield 'db:reset' => [Reset::class, 'db:reset'];
        yield 'model:phpdoc' => [Phpdoc::class, 'model:phpdoc'];
    }

    /**
     * @param class-string<Command> $class
     */
    #[Test]
    #[DataProvider('commands')]
    public function itRegistersUnderTheNamespacedName(string $class, string $name): void
    {
        $command = new $class();

        $this->assertInstanceOf(Command::class, $command);
        $this->assertSame($name, $command->name());
    }

    /**
     * Toda opção que aceita valor tem que ser OPCIONAL.
     *
     * No ahc/cli, `<valor>` marca a OPÇÃO como obrigatória, e `[valor]` como opcional — a
     * confusão é fácil porque em quase toda outra CLI os sinais dizem respeito ao valor, não à
     * opção. Escrito com `<>`, `migration:status` passava a exigir `--path` em toda invocação
     * e o comando ficava inutilizável, sem que nada além de rodá-lo revelasse isso.
     */
    #[Test]
    #[DataProvider('commands')]
    public function noOptionIsRequired(string $class, string $name): void
    {
        foreach ((new $class())->allOptions() as $option) {
            $this->assertFalse(
                $option->required(),
                "A opção {$option->long()} de {$name} está marcada como obrigatória. Use [valor] em "
                . 'vez de <valor>: no ahc/cli o sinal fala da opção, não do valor.',
            );
        }
    }

    /**
     * A lápide sai com código 1.
     *
     * É o ponto dela: um script de deploy que ainda chame `php neof migrate` tem que PARAR, em
     * vez de seguir achando que migrou. Um alias silencioso para outro comando escolheria
     * errado pelo usuário — `db:push` estragaria o dia de quem esperava versionamento, e
     * `migration:up` não criaria o banco nem semearia.
     */
    #[Test]
    public function theMigrateTombstoneExitsWithFailure(): void
    {
        $command = new Migrate();

        ob_start();
        $exitCode = $command->execute();
        $printed = (string) ob_get_clean();

        $this->assertSame(ExitCode::FAILURE, $exitCode);
        $this->assertSame(1, $exitCode, 'O código 1 é o que faz um deploy parar.');
        $this->assertStringContainsString('migration:generate', $printed);
        $this->assertStringContainsString('migration:up', $printed);
        $this->assertStringContainsString('db:reset', $printed);
    }

    /**
     * Os três códigos de saída são distintos e significam coisas diferentes.
     *
     * 2 não é "erro pior que 1": é "preciso que você decida" — rename ambíguo, confirmação de
     * remoção. A distinção existe para um script de CI poder tratar as duas situações de
     * formas diferentes, que é o motivo de haver códigos em vez de só sucesso e fracasso.
     */
    #[Test]
    public function theExitCodesAreDistinct(): void
    {
        $codes = [
            ExitCode::OK,
            ExitCode::FAILURE,
            ExitCode::NEEDS_DECISION,
        ];

        $this->assertSame([0, 1, 2], $codes);
        $this->assertCount(3, array_unique($codes));
    }

    /**
     * Todo comando de migração tem alias curto, e nenhum colide.
     *
     * Os aliases são digitados dezenas de vezes por dia; uma colisão faria um comando
     * sobrescrever o outro no registro da aplicação, em silêncio.
     */
    #[Test]
    public function theShortAliasesAreUnique(): void
    {
        $aliases = ['mg', 'mu', 'ms', 'dp', 'dl', 'dc', 'ds', 'dr', 'mp', 'mig'];

        $this->assertCount(
            count($aliases),
            array_unique($aliases),
            'Dois comandos com o mesmo alias: o segundo registrado apaga o primeiro.',
        );

        $neof = file_get_contents(__DIR__ . '/../bin/neof');

        foreach ($aliases as $alias) {
            $this->assertStringContainsString(
                "'{$alias}'",
                (string) $neof,
                "O alias '{$alias}' não está registrado em bin/neof.",
            );
        }
    }

    /**
     * `db:push` e `db:reset` sabem recusar em produção.
     *
     * A checagem em si é da biblioteca; o que se afirma aqui é que o comando NÃO oferece flag
     * para contorná-la. Um `--force` que também liberasse produção transformaria a proteção em
     * decoração, porque `--force` é o que se cola de um histórico de shell sem ler.
     */
    #[Test]
    public function noFlagOverridesTheProductionGuard(): void
    {
        foreach ([new Push(), new Reset()] as $command) {
            foreach ($command->allOptions() as $option) {
                $this->assertStringNotContainsStringIgnoringCase(
                    'prod',
                    $option->long(),
                    'Nenhuma opção pode liberar produção: a confirmação de produção não é uma flag.',
                );
            }
        }
    }
}
