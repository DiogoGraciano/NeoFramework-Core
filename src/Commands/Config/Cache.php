<?php
declare(strict_types=1);

namespace NeoFramework\Core\Commands\Config;

use Ahc\Cli\Input\Command;
use Ahc\Cli\Output\Color;
use NeoFramework\Core\Config\ConfigRepository;
use Throwable;

final class Cache extends Command
{
    public function __construct()
    {
        parent::__construct('config:cache', 'Validate configuration and write its immutable cache');
    }

    public function execute(): int
    {
        try {
            $config = ConfigRepository::fromRoot(preferCache: false);
            $config->cache();
            echo (new Color())->ok('Configuration cache written to ' . $config->cacheFile() . PHP_EOL);
            return 0;
        } catch (Throwable $error) {
            echo (new Color())->error($error->getMessage() . PHP_EOL);
            return 1;
        }
    }
}
