<?php

namespace NeoFramework\Core;

use NeoFramework\Core\Attributes\Route;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

/**
 * Mapa de rotas persistido em disco.
 *
 * Sem ele, toda requisição varre os diretórios de controllers, testa
 * class_exists em sequência e roda Reflection sobre todos os métodos da classe
 * resolvida. O mapa guarda apenas dados escalares (nome de classe, nome de
 * método, path, verbos); os atributos continuam sendo instanciados por
 * Reflection do método já resolvido, de modo que um cache desatualizado degrada
 * para o caminho normal em vez de despachar a rota errada.
 */
final class RouteCache
{
    private static ?array $map = null;

    /**
     * Mapa de rotas, vindo do arquivo em produção ou construído na hora.
     *
     * @param array<int,string> $folders Namespaces onde procurar controllers.
     */
    public static function get(array $folders): array
    {
        if (self::$map !== null) {
            return self::$map;
        }

        $cached = self::readFile();

        if ($cached !== null) {
            return self::$map = $cached;
        }

        return self::$map = self::build($folders);
    }

    /**
     * Percorre os controllers e monta o mapa.
     *
     * @param array<int,string> $folders
     */
    public static function build(array $folders): array
    {
        $map = ['controllers' => [], 'routes' => []];

        foreach ($folders as $folder) {
            foreach (self::classesInFolder($folder) as $className) {
                $routes = self::routesOf($className);

                if (!$routes) {
                    continue;
                }

                $short = self::shortName($className);
                $key = strtolower(preg_replace('/Controller$/', '', $short));

                // O primeiro namespace a declarar o nome vence, preservando a
                // ordem de precedência da varredura por diretórios.
                if ($key !== '' && !isset($map['controllers'][$key])) {
                    $map['controllers'][$key] = $className;
                }

                $map['routes'][$className] = $routes;
            }
        }

        return $map;
    }

    /**
     * Grava o mapa. Retorna false se o diretório não for gravável.
     */
    public static function store(array $map): bool
    {
        $file = self::file();
        $dir = dirname($file);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $code = "<?php\n\n// Gerado por 'neof route:cache'. Não edite à mão.\n\nreturn "
            . var_export($map, true) . ";\n";

        // Escrita atômica: um arquivo pela metade seria incluído por outra
        // requisição e derrubaria a aplicação.
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($tmp, $code, LOCK_EX) === false) {
            return false;
        }

        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }

        self::$map = $map;

        return true;
    }

    public static function clear(): bool
    {
        self::$map = null;
        $file = self::file();

        return is_file($file) ? @unlink($file) : true;
    }

    /**
     * Descarta o mapa em memória. Usado por testes.
     */
    public static function reset(): void
    {
        self::$map = null;
    }

    public static function file(): string
    {
        return Functions::getRoot() . 'Cache' . DIRECTORY_SEPARATOR . 'routes.php';
    }

    /**
     * Lê o arquivo de cache.
     *
     * Fora de produção o arquivo é ignorado: em desenvolvimento uma rota nova
     * precisa valer na hora, sem exigir a regeneração do cache.
     */
    private static function readFile(): ?array
    {
        if (env('ENVIRONMENT') !== 'prod') {
            return null;
        }

        $file = self::file();

        if (!is_file($file)) {
            return null;
        }

        $data = @include $file;

        return is_array($data) && isset($data['controllers'], $data['routes']) ? $data : null;
    }

    /**
     * @return array<int,string> FQCNs de controllers válidos no namespace.
     */
    private static function classesInFolder(string $folder): array
    {
        $dir = Functions::getRoot() . str_replace('\\', DIRECTORY_SEPARATOR, $folder);

        if (!is_dir($dir)) {
            return [];
        }

        $files = scandir($dir);

        if ($files === false) {
            return [];
        }

        sort($files, SORT_STRING);

        $classes = [];

        foreach ($files as $file) {
            if (!str_ends_with($file, '.php')) {
                continue;
            }

            $className = $folder . '\\' . substr($file, 0, -4);

            if (class_exists($className) && is_subclass_of($className, 'NeoFramework\Core\Abstract\Controller')) {
                $classes[] = $className;
            }
        }

        return $classes;
    }

    /**
     * Extrai as rotas declaradas nos métodos de um controller.
     *
     * @return array<int,array{method:string,path:string,httpMethods:array<int,string>}>
     */
    private static function routesOf(string $className): array
    {
        $routes = [];

        foreach ((new ReflectionClass($className))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes(Route::class, ReflectionAttribute::IS_INSTANCEOF);

            if (!isset($attributes[0])) {
                continue;
            }

            $route = $attributes[0]->newInstance();

            $routes[] = [
                'method' => $method->getName(),
                'path' => $route->getPath(),
                'httpMethods' => $route->getMethods(),
            ];
        }

        return $routes;
    }

    private static function shortName(string $className): string
    {
        $position = strrpos($className, '\\');

        return $position === false ? $className : substr($className, $position + 1);
    }
}
