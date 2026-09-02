<?php
declare(strict_types=1);

namespace NeoFramework\Core\Commands;

use Ahc\Cli\Input\Command;
use NeoFramework\Core\Config\AppConfig;
use NeoFramework\Core\Config\CacheConfig;
use NeoFramework\Core\Config\ConfigRepository;
use NeoFramework\Core\Config\LoggingConfig;

final class About extends Command
{
    public function __construct()
    {
        parent::__construct('about', 'Show application and framework runtime information');
        $this->option('-j --json', 'Emit stable JSON for automation');
    }

    public function execute(): int
    {
        $details = self::details(ConfigRepository::fromRoot(), \NeoFramework\Core\Support\ProjectRoot::path(), dirname(__DIR__, 2));
        if ($this->json) {
            echo json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
            return ExitCode::OK;
        }

        foreach ($details as $key => $value) echo str_pad($key, 16) . $value . PHP_EOL;

        return ExitCode::OK;
    }

    /** @return array{framework:string,php:string,environment:string,root:string,config_cache:string,route_cache:string,cache_adapter:string,log_stream:string} */
    public static function details(ConfigRepository $config, string $root, string $frameworkRoot): array
    {
        $package = self::package($frameworkRoot);
        $app = AppConfig::from($config);
        $cache = CacheConfig::from($config);
        $logging = LoggingConfig::from($config);

        return [
            'framework' => $package,
            'php' => PHP_VERSION,
            'environment' => $app->environment,
            'root' => rtrim($root, '/\\'),
            'config_cache' => is_file($config->cacheFile()) ? 'enabled' : 'disabled',
            'route_cache' => is_file(rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'Cache' . DIRECTORY_SEPARATOR . 'routes.php') ? 'enabled' : 'disabled',
            'cache_adapter' => $cache->adapter,
            'log_stream' => $logging->stream,
        ];
    }

    private static function package(string $frameworkRoot): string
    {
        $file = rtrim($frameworkRoot, '/\\') . DIRECTORY_SEPARATOR . 'composer.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($data)) return 'NeoFramework Core';
        $name = is_string($data['name'] ?? null) ? $data['name'] : 'NeoFramework Core';
        $version = is_string($data['version'] ?? null) ? $data['version'] : (string) ($data['extra']['branch-alias']['dev-main'] ?? 'dev');

        return $name . ' ' . $version;
    }
}
