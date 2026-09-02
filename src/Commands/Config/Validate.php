<?php
declare(strict_types=1);

namespace NeoFramework\Core\Commands\Config;

use Ahc\Cli\Input\Command;
use Ahc\Cli\Output\Color;
use NeoFramework\Core\Config\ConfigRepository;
use NeoFramework\Core\Config\ConfigValidator;
use Throwable;

final class Validate extends Command
{
    public function __construct()
    {
        parent::__construct('config:validate', 'Validate configuration without writing a cache');
    }

    public function execute(): int
    {
        try {
            $config = ConfigRepository::fromRoot(preferCache: false);
            ConfigValidator::validate($config->fresh());
            echo (new Color())->ok('Configuration is valid.' . PHP_EOL);
            return 0;
        } catch (Throwable $error) {
            echo (new Color())->error($error->getMessage() . PHP_EOL);
            return 1;
        }
    }
}
