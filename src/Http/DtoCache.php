<?php
declare(strict_types=1);

namespace NeoFramework\Core\Http;

use NeoFramework\Core\RouteCache;
use NeoFramework\Core\Support\ProjectRoot;

/**
 * Metadata de DTO compilada, no mesmo molde de `route:cache`.
 *
 * O memo de `DtoMetadata` vive no processo, o que resolve o custo num runtime
 * persistente e não resolve nada sob PHP-FPM: lá cada requisição é um processo
 * novo e paga a Reflection inteira de novo.
 */
final class DtoCache
{
    /** @var array<class-string, list<array<string,mixed>>>|null */
    private static ?array $map = null;

    private function __construct()
    {
    }

    /**
     * Compila todo DTO alcançável a partir das actions.
     *
     * A descoberta parte do mapa de rotas porque é ele que define o que a
     * aplicação realmente expõe. Um DTO que nenhuma action recebe não é
     * carregado em requisição nenhuma, e compilá-lo só engordaria o arquivo.
     *
     * @return array<class-string, list<array<string,mixed>>>
     */
    public static function build(): array
    {
        // Compilar lendo o cache anterior reproduziria o mapa velho. Zerar antes
        // força a descoberta a partir da Reflection, que é a fonte da verdade.
        self::$map = [];
        DtoMetadata::reset();

        $compiled = [];

        foreach (self::discover() as $class) {
            $spec = DtoMetadata::compile($class);
            // Uma classe com default não exportável fica de fora e continua
            // passando por Reflection: melhor um cache parcial do que um mapa
            // que reconstrói o DTO com o default errado.
            if ($spec !== null) $compiled[$class] = $spec;
        }

        ksort($compiled);

        return $compiled;
    }

    /** @return list<string> */
    public static function discover(): array
    {
        $map = RouteCache::build();
        $found = [];

        $groups = [...array_values($map['static']), ...array_values($map['dynamic'])];
        foreach ($map['staticHost'] ?? [] as $byPath) {
            foreach ($byPath as $records) $groups[] = $records;
        }

        foreach ($groups as $records) {
            foreach ($records as $record) {
                $controller = $record['controller'] ?? null;
                $action = $record['action'] ?? null;
                if (!is_string($controller) || !is_string($action) || !method_exists($controller, $action)) continue;

                foreach ((new \ReflectionMethod($controller, $action))->getParameters() as $parameter) {
                    $type = $parameter->getType();
                    if (!$type instanceof \ReflectionNamedType) continue;
                    self::collect($type->getName(), $found);
                }
            }
        }

        return array_keys($found);
    }

    /**
     * Segue DTOs aninhados e elementos de `#[ListOf]`.
     *
     * @param array<string,true> $found
     * @param-out array<string,true> $found
     */
    private static function collect(string $class, array &$found): void
    {
        if (isset($found[$class]) || !DtoBinder::supports($class)) return;

        $found[$class] = true;

        foreach (DtoMetadata::of($class) as $parameter) {
            if ($parameter->type !== null) self::collect($parameter->type, $found);
            if ($parameter->listElementType !== null) self::collect($parameter->listElementType, $found);
        }
    }

    /** @param array<class-string, list<array<string,mixed>>> $map */
    public static function store(array $map): bool
    {
        $file = self::file();
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return false;

        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $code = "<?php\n\n// Gerado por 'neof dto:cache'. Não edite.\nreturn " . var_export($map, true) . ";\n";
        if (@file_put_contents($tmp, $code, LOCK_EX) === false) return false;
        if (!@rename($tmp, $file)) {
            @unlink($tmp);

            return false;
        }
        self::$map = $map;

        return true;
    }

    /** @return array<class-string, list<array<string,mixed>>>|null */
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
        return ProjectRoot::path() . 'Cache' . DIRECTORY_SEPARATOR . 'dto.php';
    }
}
