<?php
declare(strict_types=1);

namespace NeoFramework\Core\Runtime;

use NeoFramework\Core\Config\CacheConfig;
use NeoFramework\Core\Config\ConfigRepositoryInterface;
use NeoFramework\Core\Config\LoggingConfig;
use NeoFramework\Core\Config\RuntimeConfig;
use NeoFramework\Core\Http\ResettableInterface;

/** Verificações sem efeito colateral para habilitar um runtime persistente. */
final class RuntimeDoctor
{
    /**
     * @return list<array{id:string,status:'ok'|'warning'|'error',message:string}>
     */
    public static function inspect(ConfigRepositoryInterface $config, string $root): array
    {
        $checks = [self::phpVersion(), self::logTarget($config, $root), self::cacheTarget($config, $root)];

        foreach (RuntimeConfig::from($config)->statefulServices as $service) {
            $checks[] = self::statefulService($service);
        }

        $checks[] = function_exists('frankenphp_handle_request')
            ? self::check('frankenphp', 'ok', 'FrankenPHP worker API detected.')
            : self::check('frankenphp', 'warning', 'FrankenPHP worker API not detected; this is expected outside a FrankenPHP worker.');

        return $checks;
    }

    /** @param list<array{id:string,status:string,message:string}> $checks */
    public static function hasErrors(array $checks): bool
    {
        return in_array('error', array_column($checks, 'status'), true);
    }

    /** @return array{id:string,status:'ok'|'warning'|'error',message:string} */
    private static function phpVersion(): array
    {
        return version_compare(PHP_VERSION, '8.4.0', '>=')
            ? self::check('php', 'ok', 'PHP ' . PHP_VERSION . ' meets the required version.')
            : self::check('php', 'error', 'PHP 8.4 or newer is required for persistent runtimes.');
    }

    /** @return array{id:string,status:'ok'|'warning'|'error',message:string} */
    private static function logTarget(ConfigRepositoryInterface $config, string $root): array
    {
        $logging = LoggingConfig::from($config);
        if ($logging->stream !== 'file') return self::check('logging', 'ok', 'Logging uses ' . $logging->stream . '.');

        $path = str_starts_with($logging->path, '/') ? $logging->path : rtrim($root, '/\\') . DIRECTORY_SEPARATOR . $logging->path;
        $writable = is_file($path) ? is_writable($path) : is_dir(dirname($path)) && is_writable(dirname($path));

        return $writable
            ? self::check('logging', 'ok', "Log target is writable: {$path}")
            : self::check('logging', 'error', "Log target is not writable: {$path}");
    }

    /** @return array{id:string,status:'ok'|'warning'|'error',message:string} */
    private static function cacheTarget(ConfigRepositoryInterface $config, string $root): array
    {
        $cache = CacheConfig::from($config);
        if ($cache->adapter !== 'filesystem') return self::check('cache', 'ok', 'Cache adapter is ' . $cache->adapter . '.');

        $path = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'Cache';
        $writable = is_dir($path) ? is_writable($path) : is_writable(dirname($path));

        return $writable
            ? self::check('cache', 'ok', "Cache directory is writable: {$path}")
            : self::check('cache', 'error', "Cache directory is not writable: {$path}");
    }

    /** @return array{id:string,status:'ok'|'warning'|'error',message:string} */
    private static function statefulService(string $service): array
    {
        $id = 'service:' . $service;
        if (!class_exists($service)) return self::check($id, 'error', "Stateful service class does not exist: {$service}");
        if (!is_subclass_of($service, ResettableInterface::class)) return self::check($id, 'error', "Stateful service must implement " . ResettableInterface::class . ": {$service}");

        return self::check($id, 'ok', "Stateful service is resettable: {$service}");
    }

    /** @return array{id:string,status:'ok'|'warning'|'error',message:string} */
    private static function check(string $id, string $status, string $message): array
    {
        return ['id' => $id, 'status' => $status, 'message' => $message];
    }
}
