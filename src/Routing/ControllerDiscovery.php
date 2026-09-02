<?php
declare(strict_types=1);

namespace NeoFramework\Core\Routing;

use NeoFramework\Core\Abstract\Controller;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ControllerDiscovery
{
    public function discover(): array
    {
        $base = \NeoFramework\Core\Support\ProjectRoot::path() . 'App' . DIRECTORY_SEPARATOR . 'Controllers';
        if (!is_dir($base)) return [];
        $classes = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') continue;
            $relative = substr($file->getPathname(), strlen($base) + 1, -4);
            $class = 'App\\Controllers\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
            if (class_exists($class) && is_subclass_of($class, Controller::class)) $classes[] = $class;
        }
        sort($classes);
        return $classes;
    }
}
