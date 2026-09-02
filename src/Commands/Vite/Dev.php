<?php
declare(strict_types=1);

namespace NeoFramework\Core\Commands\Vite;

use Ahc\Cli\Input\Command;
use Ahc\Cli\Output\Color;
use NeoFramework\Core\ViteToolchain;

class Dev extends Command
{
    public function __construct()
    {
        parent::__construct("vite:dev", "Sobe o dev server do Vite com HMR");

        $this->option("-p --port [port]", "Porta do dev server");
        $this->option("-H --host [host]", "Interface de escuta");

        $this->version("1.0");
    }

    public function execute($port, $host)
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

        // Flag não passada chega como null, e não como false.
        $arguments = [];

        if ($port !== null) {
            $arguments[] = '--port';
            $arguments[] = (string) $port;
        }

        if ($host !== null) {
            $arguments[] = '--host';
            $arguments[] = (string) $host;
        }

        echo $color->info("Inclua {neof_vite} no <head> do seu layout." . PHP_EOL);

        exit($toolchain->run($arguments));
    }
}
