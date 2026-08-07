<?php

namespace NeoFramework\Core\Commands\Route;

use Ahc\Cli\Input\Command;
use Ahc\Cli\Output\Color;
use NeoFramework\Core\RouteCache;
use Throwable;

class Clear extends Command
{
    public function __construct()
    {
        parent::__construct("route:clear", "Remove the generated route map");

        $this->version("1.0");
    }

    public function execute()
    {
        $color = new Color;

        try {
            if (RouteCache::clear()) {
                echo $color->ok("Route map removed." . PHP_EOL);
                return;
            }

            echo $color->error("Could not remove " . RouteCache::file() . PHP_EOL);
        } catch (Throwable $e) {
            echo $color->error($e->getMessage() . PHP_EOL);
        }
    }
}
