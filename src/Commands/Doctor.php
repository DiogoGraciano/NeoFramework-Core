<?php
declare(strict_types=1);

namespace NeoFramework\Core\Commands;

use Ahc\Cli\Input\Command;
use NeoFramework\Core\Config\ConfigRepository;
use NeoFramework\Core\Runtime\RuntimeDoctor;

final class Doctor extends Command
{
    public function __construct()
    {
        parent::__construct('doctor', 'Check deployment and persistent-runtime safety');
        $this->option('-r --runtime', 'Run persistent-runtime checks')
            ->option('-j --json', 'Emit stable JSON for automation');
    }

    public function execute(): int
    {
        if (!$this->runtime) {
            fwrite(STDERR, "Use doctor --runtime to run persistent-runtime checks.\n");
            return ExitCode::NEEDS_DECISION;
        }

        $checks = RuntimeDoctor::inspect(ConfigRepository::fromRoot(), \NeoFramework\Core\Support\ProjectRoot::path());
        if ($this->json) {
            echo json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        } else {
            foreach ($checks as $check) {
                echo strtoupper($check['status']) . '  ' . $check['id'] . ' — ' . $check['message'] . PHP_EOL;
            }
        }

        return RuntimeDoctor::hasErrors($checks) ? ExitCode::FAILURE : ExitCode::OK;
    }
}
