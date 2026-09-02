<?php
declare(strict_types=1);

namespace NeoFramework\Core\Commands;

use Ahc\Cli\Input\Command;
use NeoFramework\Core\Support\StubPublisher;

final class StubsPublish extends Command
{
    public function __construct()
    {
        parent::__construct('stubs:publish', 'Publish framework stubs to resources/stubs');
        $this->option('-f --force', 'Overwrite an existing project stub')->option('-j --json', 'Emit stable JSON for automation');
    }

    public function execute(?bool $force): int
    {
        try {
            $result = (new StubPublisher(\NeoFramework\Core\Support\ProjectRoot::path(), dirname(__DIR__, 2)))->publish((bool) $force);
            if ($this->json) echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
            else echo "Published {$result['created']} stub(s); {$result['exists']} unchanged.\n";
            return ExitCode::OK;
        } catch (\Throwable $error) {
            fwrite(STDERR, $error->getMessage() . PHP_EOL);
            return ExitCode::FAILURE;
        }
    }
}
