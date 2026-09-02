<?php

declare(strict_types=1);

namespace NeoFramework\Core\Support;

use Ahc\Cli\Output\Color;
use Diogodg\Neoorm\Support\Output\Output;

/**
 * A saída da NeoORM ligada ao terminal.
 *
 * A biblioteca não imprime nada por conta própria — o default dela é `NullOutput` — e é o
 * entrypoint de CLI que decide para onde o texto vai. É por isso que os comandos de migração
 * podem ser chamados de um job, de um deploy ou de uma rota administrativa sem que ninguém
 * receba `echo` no meio de uma resposta HTTP.
 */
final class ConsoleOutput implements Output
{
    private readonly Color $color;

    public function __construct(private readonly bool $quiet = false)
    {
        $this->color = new Color();
    }

    public function write(string $message): void
    {
        if (!$this->quiet) {
            echo $message . PHP_EOL;
        }
    }

    public function success(string $message): void
    {
        if (!$this->quiet) {
            echo $this->color->ok($message . PHP_EOL);
        }
    }

    public function warning(string $message): void
    {
        if (!$this->quiet) {
            echo $this->color->warn($message . PHP_EOL);
        }
    }

    /**
     * Erro sai mesmo em modo silencioso, e vai para STDERR.
     *
     * As duas coisas pela mesma razão: quem redireciona a saída de um deploy para um arquivo
     * de log não pode perder justamente a mensagem que explica por que ele falhou.
     */
    public function error(string $message): void
    {
        fwrite(STDERR, $this->color->error($message . PHP_EOL));
    }
}
