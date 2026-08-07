<?php

namespace NeoFramework\Core\Commands\Route;

use Ahc\Cli\Input\Command;
use Ahc\Cli\Output\Color;
use NeoFramework\Core\Functions;
use NeoFramework\Core\RouteCache;
use Throwable;

class Cache extends Command
{
    public function __construct()
    {
        parent::__construct("route:cache", "Generate the route map consumed in production");

        $this->version("1.0");
    }

    public function execute()
    {
        $color = new Color;

        try {
            $map = RouteCache::build($this->controllerNamespaces());

            $controllers = count($map['controllers']);
            $routes = array_sum(array_map('count', $map['routes']));

            if (!RouteCache::store($map)) {
                echo $color->error("Could not write " . RouteCache::file() . PHP_EOL);
                return;
            }

            echo $color->ok("Route map written to " . RouteCache::file() . PHP_EOL);
            echo $color->info("{$controllers} controllers, {$routes} routes" . PHP_EOL);
        } catch (Throwable $e) {
            echo $color->error($e->getMessage() . PHP_EOL . $e->getTraceAsString());
        }
    }

    /**
     * Namespaces varridos: a raiz de App/Controllers e cada subdiretório.
     */
    private function controllerNamespaces(): array
    {
        $namespaces = ["App\Controllers"];
        $folder = Functions::getRoot() . "App/Controllers";

        if (!is_dir($folder)) {
            return $namespaces;
        }

        $files = scandir($folder);

        if ($files === false) {
            return $namespaces;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            if (is_dir($folder . DIRECTORY_SEPARATOR . $file)) {
                $namespaces[] = "App\Controllers\\" . $file;
            }
        }

        return $namespaces;
    }
}
