<?php
declare(strict_types=1);

namespace NeoFramework\Core;

/**
 * Localiza e executa o binário do Vite.
 *
 * Separado de Vite para que a renderização de tags — que roda a cada requisição —
 * não carregue nada relacionado a processo externo.
 *
 * @author Diogo Graciano
 */
final class ViteToolchain
{
    private const CONFIG_NAMES = [
        'vite.config.js',
        'vite.config.mjs',
        'vite.config.ts',
        'vite.config.mts',
    ];

    public function __construct(private string $root)
    {
        $this->root = rtrim($root, '/') . '/';
    }

    /**
     * Problemas que impedem o Vite de rodar, na ordem em que devem ser
     * resolvidos. Array vazio quando está tudo pronto.
     *
     * @return list<string>
     */
    public function diagnose(): array
    {
        $problems = [];

        if (!is_file($this->root . 'package.json')) {
            $problems[] = "package.json não encontrado em {$this->root}." . PHP_EOL
                . "  Os assets passaram a ser compilados pelo Vite. Copie o package.json e o"
                . " vite.config.js do skeleton do NeoFramework.";
        }

        if ($this->configFile() === null) {
            $problems[] = "vite.config.js não encontrado em {$this->root}.";
        }

        if ($this->binary() === null) {
            $problems[] = "node_modules/.bin/vite não encontrado." . PHP_EOL
                . "  Rode `npm install` (ou pnpm/yarn/bun install) na raiz do projeto.";
        }

        return $problems;
    }

    public function configFile(): ?string
    {
        foreach (self::CONFIG_NAMES as $name) {
            if (is_file($this->root . $name)) {
                return $this->root . $name;
            }
        }

        return null;
    }

    /** O binário local atende npm, pnpm, yarn e bun igualmente. */
    public function binary(): ?string
    {
        $path = $this->root . 'node_modules' . DIRECTORY_SEPARATOR . '.bin' . DIRECTORY_SEPARATOR . 'vite';

        return is_file($path) ? $path : null;
    }

    /**
     * Monta a linha de comando. Separado da execução justamente para poder ser
     * verificado sem rodar nada.
     *
     * @param list<string> $arguments
     */
    public function command(array $arguments): string
    {
        $binary = escapeshellarg((string) $this->binary());

        if ($arguments === []) {
            return $binary;
        }

        return $binary . ' ' . implode(' ', array_map('escapeshellarg', $arguments));
    }

    /**
     * Roda o Vite herdando stdout/stderr, e devolve o status de saída.
     *
     * passthru(), e não Ahc\Cli\Helper\Shell: o Shell usa proc_open com pipes e
     * só entrega a saída quando o processo termina — o que, para `vite` em modo
     * dev, é nunca. O build também ficaria mudo por minutos, e barra de progresso
     * não sobrevive a um pipe.
     *
     * @param list<string> $arguments
     */
    public function run(array $arguments): int
    {
        $status = 0;
        $previous = getcwd();

        // O Vite resolve o config e escreve o arquivo `hot` a partir do diretório
        // corrente, que precisa ser a raiz do projeto independente de onde o
        // comando foi invocado.
        chdir($this->root);

        try {
            passthru($this->command($arguments), $status);
        } finally {
            if ($previous !== false) {
                chdir($previous);
            }
        }

        return $status;
    }
}
