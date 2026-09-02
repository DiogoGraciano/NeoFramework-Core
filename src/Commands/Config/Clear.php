<?php
declare(strict_types=1);

namespace NeoFramework\Core\Commands\Config;

use Ahc\Cli\Input\Command;
use Ahc\Cli\Output\Color;
use NeoFramework\Core\Config\ConfigRepository;
use Throwable;

final class Clear extends Command
{
    public function __construct()
    {
        parent::__construct('config:clear', 'Remove the generated configuration cache');
    }

    public function execute(): int
    {
        try {
            $config = ConfigRepository::fromRoot();
            if (!$config->clearCache()) {
                throw new \RuntimeException('Unable to remove ' . $config->cacheFile());
            }
            echo (new Color())->ok('Configuration cache removed.' . PHP_EOL);
            return 0;
        } catch (Throwable $error) {
            echo (new Color())->error($error->getMessage() . PHP_EOL);
            return 1;
        }
    }
}
