<?php
declare(strict_types=1);

namespace NeoFramework\Core\Support;

final class ProjectRoot
{
    private static ?string $path = null;

    private function __construct()
    {
    }

    public static function path(): string
    {
        if (self::$path !== null) {
            return self::$path;
        }

        $explicit = $_ENV['NEOFRAMEWORK_ROOT'] ?? $_SERVER['NEOFRAMEWORK_ROOT'] ?? null;
        if (is_string($explicit) && $explicit !== '') {
            return self::$path = self::withTrailingSeparator($explicit);
        }

        $cwd = getcwd();
        $found = ($cwd !== false ? self::findUp($cwd, true) : null)
            ?? self::findUp(__DIR__, true)
            ?? self::findUp(__DIR__, false);

        return self::$path = self::withTrailingSeparator($found ?? ($cwd !== false ? $cwd : __DIR__));
    }

    public static function set(?string $path): void
    {
        self::$path = $path === null ? null : self::withTrailingSeparator($path);
    }

    private static function findUp(string $from, bool $requireApplication): ?string
    {
        $directory = realpath($from);
        if ($directory === false) {
            return null;
        }

        for ($depth = 0; $depth < 32; $depth++) {
            if (is_file($directory . DIRECTORY_SEPARATOR . 'composer.json')) {
                if ($requireApplication && is_dir($directory . DIRECTORY_SEPARATOR . 'App')) {
                    return $directory;
                }

                if (!$requireApplication && !str_contains($directory . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
                    return $directory;
                }
            }

            $parent = dirname($directory);
            if ($parent === $directory) {
                return null;
            }

            $directory = $parent;
        }

        return null;
    }

    private static function withTrailingSeparator(string $path): string
    {
        return rtrim($path, '/\\') . DIRECTORY_SEPARATOR;
    }
}
