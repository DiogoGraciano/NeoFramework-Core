<?php
declare(strict_types=1);

namespace NeoFramework\Core\Commands;

use Ahc\Cli\Input\Command;
use Ahc\Cli\Output\Color;
use NeoFramework\Core\Vite;
use NeoFramework\Core\ViteToolchain;

class Build extends Command
{
    public function __construct()
    {
        parent::__construct("build", "Compila os assets de resources/ com o Vite");

        $this->option("-m --mode [mode]", "Modo do Vite", null, "production");

        $this->version("1.0");
    }

    public function execute($mode)
    {
        $color = new Color;
        $toolchain = new ViteToolchain(\NeoFramework\Core\Support\ProjectRoot::path());

        $problems = $toolchain->diagnose();

        if ($problems !== []) {
            foreach ($problems as $problem) {
                echo $color->error($problem . PHP_EOL);
            }

            exit(1);
        }

        // O status precisa ser propagado: com o `return` silencioso da versão
        // anterior um build quebrado saía com 0, e um `neof build && deploy`
        // publicava assets inexistentes com sucesso aparente.
        $status = $toolchain->run(['build', '--mode', (string) $mode]);

        if ($status !== 0) {
            echo $color->error("O build do Vite terminou com status {$status}." . PHP_EOL);

            exit($status);
        }

        echo $color->ok("Assets compilados. Manifest: " . Vite::instance()->manifestFile() . PHP_EOL);
    }
}
