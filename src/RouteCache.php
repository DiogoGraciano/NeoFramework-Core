<?php
declare(strict_types=1);

namespace NeoFramework\Core;

use NeoFramework\Core\Routing\AttributeLoader;
use NeoFramework\Core\Routing\ControllerDiscovery;
use NeoFramework\Core\Routing\RouteCompiler;
use NeoFramework\Core\Routing\RouteRegistrar;

/** Compiled routing table persisted for production. */
final class RouteCache
{
    private static ?array $map = null;

    public static function get(array $ignored = []): array
    {
        if (self::$map !== null) return self::$map;
        if (Config::get('app.environment', 'dev') === 'prod' && is_file(self::file())) {
            $cached = include self::file();
            if (is_array($cached) && isset($cached['static'], $cached['staticHost'], $cached['dynamic'], $cached['named'], $cached['fallback'])) return self::$map = $cached;
        }
        return self::$map = self::build();
    }

    public static function build(array $ignored = []): array
    {
        $controllers = (new ControllerDiscovery())->discover();
        $collection = (new AttributeLoader())->load($controllers);

        // As rotas programáticas entram na MESMA coleção: assim a checagem de
        // nome e de rota duplicada vale entre os dois estilos. Compilar em
        // separado deixaria uma rota de `Config/routes.php` sombrear
        // silenciosamente uma declarada por atributo.
        foreach (self::programmatic()->all() as $route) $collection->add($route);

        return RouteCompiler::compile($collection);
    }

    /** Rotas declaradas em `Config/routes.php`, se o arquivo existir. */
    public static function programmatic(): RouteRegistrar
    {
        $registrar = new RouteRegistrar();
        $file = Support\ProjectRoot::path() . 'Config' . DIRECTORY_SEPARATOR . 'routes.php';
        if (!is_file($file)) return $registrar;

        $declare = include $file;
        if (!is_callable($declare)) {
            throw new \LogicException("Config/routes.php deve retornar um callable que recebe o RouteRegistrar.");
        }

        $declare($registrar);

        return $registrar;
    }

    public static function store(array $map): bool
    {
        $file = self::file(); $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return false;
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $code = "<?php\n\n// Gerado por 'neof route:cache'. Não edite.\nreturn " . var_export($map, true) . ";\n";
        if (@file_put_contents($tmp, $code, LOCK_EX) === false) return false;
        if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
        self::$map = $map; return true;
    }

    public static function clear(): bool
    {
        self::$map = null;
        return !is_file(self::file()) || @unlink(self::file());
    }
    public static function reset(): void { self::$map = null; }
    public static function file(): string { return \NeoFramework\Core\Support\ProjectRoot::path() . 'Cache' . DIRECTORY_SEPARATOR . 'routes.php'; }
}
