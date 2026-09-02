<?php

declare(strict_types=1);

namespace NeoFramework\Core\Events;

use NeoFramework\Core\Support\ProjectRoot;
use Psr\Container\ContainerInterface;

/**
 * Mapa de listeners compilado, no mesmo molde de `route:cache`.
 *
 * Sem ele, toda requisição faz `include Config/events.php` e revalida cada
 * listener com `class_exists` e `method_exists`. É trabalho idêntico em todo
 * request de produção, sobre um arquivo que não muda entre deploys.
 */
final class ListenerCache
{
    /** @var array<class-string, list<array{listener: class-string, priority: int}>>|null */
    private static ?array $map = null;

    private function __construct()
    {
    }

    /** Compila `Config/events.php`, validando cada entrada. */
    public static function build(): array
    {
        $file = self::source();

        return is_file($file) ? ListenerProvider::fromConfig((array) include $file)->compilable() : [];
    }

    /** @param array<class-string, list<array{listener: class-string, priority: int}>> $map */
    public static function store(array $map): bool
    {
        $file = self::file();
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return false;

        // Grava em temporário e renomeia: `rename` é atômico no mesmo sistema de
        // arquivos, então nenhuma requisição chega a ver um mapa pela metade.
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $code = "<?php\n\n// Gerado por 'neof event:cache'. Não edite.\nreturn " . var_export($map, true) . ";\n";
        if (@file_put_contents($tmp, $code, LOCK_EX) === false) return false;
        if (!@rename($tmp, $file)) {
            @unlink($tmp);

            return false;
        }
        self::$map = $map;

        return true;
    }

    /** @return array<class-string, list<array{listener: class-string, priority: int}>>|null */
    public static function load(): ?array
    {
        if (self::$map !== null) return self::$map;

        $file = self::file();
        if (!is_file($file)) return null;

        /** @var mixed $map */
        $map = include $file;

        return is_array($map) ? self::$map = $map : null;
    }

    public static function clear(): bool
    {
        self::$map = null;

        return !is_file(self::file()) || @unlink(self::file());
    }

    public static function reset(): void
    {
        self::$map = null;
    }

    public static function file(): string
    {
        return ProjectRoot::path() . 'Cache' . DIRECTORY_SEPARATOR . 'events.php';
    }

    public static function source(): string
    {
        return ProjectRoot::path() . 'Config' . DIRECTORY_SEPARATOR . 'events.php';
    }

    /** Provider a partir do cache quando ele existe, do arquivo de configuração quando não. */
    public static function provider(?ContainerInterface $container = null): ListenerProvider
    {
        $compiled = self::load();
        if ($compiled !== null) return ListenerProvider::fromCompiled($compiled, $container);

        $file = self::source();

        return ListenerProvider::fromConfig(is_file($file) ? (array) include $file : [], $container);
    }
}
