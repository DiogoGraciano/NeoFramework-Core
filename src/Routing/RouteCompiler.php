<?php
declare(strict_types=1);

namespace NeoFramework\Core\Routing;

final class RouteCompiler
{
    public static function compile(RouteCollection $collection): array
    {
        $compiled = ['static' => [], 'staticHost' => [], 'dynamic' => [], 'named' => [], 'fallback' => []];

        foreach (self::ordered($collection->all()) as $route) {
            $pattern = PatternCompiler::compile($route->path);
            $record = $route->toArray() + [
                'regex' => $pattern['regex'],
                'variables' => $pattern['variables'],
                'constraints' => $pattern['constraints'],
                'hostRegex' => null,
                'hostVariables' => [],
            ];

            if ($route->host !== null) {
                $host = PatternCompiler::compileHost($route->host);
                $shared = array_intersect($pattern['variables'], $host['variables']);
                if ($shared !== []) throw new \LogicException("A rota {$route->path} declara {" . reset($shared) . '} no path e no host.');

                $record['hostRegex'] = $host['regex'];
                $record['hostVariables'] = $host['variables'];
                $record['constraints'] += $host['constraints'];
            }

            foreach ($route->methods as $method) {
                $method = strtoupper($method);
                // Rota estática com host vai para um balde próprio, indexado por
                // path mas guardando uma LISTA: o balde comum é chaveado só pelo
                // path, então dois hosts com o mesmo path se sobrescreveriam e
                // um deles sumiria em silêncio.
                if ($pattern['static'] && $route->host !== null) $compiled['staticHost'][$method][$pattern['path']][] = $record;
                elseif ($pattern['static']) $compiled['static'][$method][$pattern['path']] = $record;
                else $compiled['dynamic'][$method][] = $record;
            }

            if ($route->name !== null) $compiled['named'][$route->name] = $record;
        }

        foreach ($collection->fallbacks() as $method => $route) {
            $compiled['fallback'][$method] = $route->toArray() + ['regex' => '', 'variables' => [], 'constraints' => [], 'hostRegex' => null, 'hostVariables' => []];
        }

        return $compiled;
    }

    /**
     * Ordena por prioridade decrescente, preservando a ordem de declaração no empate.
     *
     * `usort` é estável desde o PHP 8, então rotas de mesma prioridade mantêm a
     * sequência em que foram declaradas — que é o comportamento que existia
     * antes da prioridade e continua sendo o padrão.
     *
     * @param list<RouteDefinition> $routes
     * @return list<RouteDefinition>
     */
    private static function ordered(array $routes): array
    {
        usort($routes, static fn (RouteDefinition $a, RouteDefinition $b): int => $b->priority <=> $a->priority);

        return $routes;
    }
}
