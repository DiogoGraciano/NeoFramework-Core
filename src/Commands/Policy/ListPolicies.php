<?php
declare(strict_types=1);

namespace NeoFramework\Core\Commands\Policy;

use Ahc\Cli\Input\Command;
use NeoFramework\Core\Auth\PolicyRegistry;
use NeoFramework\Core\Container;

final class ListPolicies extends Command
{
    public function __construct()
    {
        parent::__construct('policy:list', 'List registered authorization abilities');
        $this->option('-j --json', 'Output as JSON');
    }

    public function execute(): int
    {
        $abilities = (new Container())->get(PolicyRegistry::class)->abilities();
        if ($this->json) {
            echo json_encode($abilities, JSON_THROW_ON_ERROR) . PHP_EOL;
        } elseif ($abilities === []) {
            echo "No authorization abilities registered.\n";
        } else {
            foreach ($abilities as $ability) echo $ability . PHP_EOL;
        }

        return 0;
    }
}
