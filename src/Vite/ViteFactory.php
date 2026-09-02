<?php
declare(strict_types=1);

namespace NeoFramework\Core\Vite;

use InvalidArgumentException;
use NeoFramework\Core\Support\ProjectRoot;
use NeoFramework\Core\Vite;

/** Builds the default Vite façade from an application's file-backed settings. */
final class ViteFactory
{
    private const DEFAULT_ENTRYPOINTS = ['resources/css/app.css', 'resources/js/app.js'];

    private function __construct()
    {
    }

    public static function fromProjectRoot(): Vite
    {
        return self::fromRoot(ProjectRoot::path());
    }

    public static function fromRoot(string $root): Vite
    {
        $configFile = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . 'vite.config.php';
        $config = is_file($configFile) ? include $configFile : [];

        if (!is_array($config)) {
            throw new InvalidArgumentException("Vite configuration in {$configFile} must return an array.");
        }

        $entrypoints = $config['entrypoints'] ?? self::DEFAULT_ENTRYPOINTS;
        if (!is_array($entrypoints) || array_filter($entrypoints, static fn (mixed $entrypoint): bool => !is_string($entrypoint)) !== []) {
            throw new InvalidArgumentException("Vite configuration key 'entrypoints' must be a list of strings.");
        }

        $hotFile = $config['hot_file'] ?? 'hot';
        $buildPath = $config['build_path'] ?? 'build';
        if (!is_string($hotFile) || !is_string($buildPath)) {
            throw new InvalidArgumentException("Vite configuration keys 'hot_file' and 'build_path' must be strings.");
        }

        $root = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;

        return new Vite(
            publicPath: $root . 'public',
            hotFile: $root . ltrim($hotFile, '/'),
            buildPath: $buildPath,
            urlBase: null,
            entrypoints: array_values($entrypoints),
        );
    }
}
