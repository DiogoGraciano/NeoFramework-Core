<?php
declare(strict_types=1);

namespace NeoFramework\Core\Commands\Route;

use Ahc\Cli\Input\Command;
use Ahc\Cli\Output\Color;
use NeoFramework\Core\Config;
use NeoFramework\Core\Config\RateLimitConfig;
use NeoFramework\Core\RouteCache;
use NeoFramework\Core\Routing\RouteConflictDetector;
use Throwable;

class Cache extends Command
{
    public function __construct()
    {
        parent::__construct("route:cache", "Generate the route map consumed in production");

        $this->version("1.0");
    }

    public function execute(): int
    {
        $color = new Color;

        try {
            $map = RouteCache::build();
            $routes = 0;
            foreach ($map['static'] as $items) $routes += count($items);
            foreach ($map['staticHost'] as $items) foreach ($items as $records) $routes += count($records);
            foreach ($map['dynamic'] as $items) $routes += count($items);

            $conflicts = RouteConflictDetector::detect($map);
            if ($conflicts !== []) {
                // Uma rota que nunca roda não gera erro em runtime: ela apenas
                // não acontece. Recusar aqui é a única chance de alguém saber.
                echo $color->error('Rotas inalcançáveis:' . PHP_EOL);
                foreach ($conflicts as $conflict) echo $color->error('  ' . $conflict . PHP_EOL);
                echo $color->info('Declare a rota mais específica antes da mais genérica.' . PHP_EOL);
                return 1;
            }

            $unknown = self::unknownPolicies($map);
            if ($unknown !== []) {
                // Um `#[RateLimit(policy: ...)]` sem entrada em `Config/rate_limit.php`
                // só falharia ao servir a rota. Recusar aqui mantém a promessa de que
                // atributo escrito é atributo que funciona.
                echo $color->error('Políticas de rate limit inexistentes: ' . implode(', ', $unknown) . PHP_EOL);
                echo $color->info("Declare-as em Config/rate_limit.php ou corrija o atributo." . PHP_EOL);
                return 1;
            }

            if (!RouteCache::store($map)) {
                echo $color->error("Could not write " . RouteCache::file() . PHP_EOL);
                return 1;
            }

            echo $color->ok("Route map written to " . RouteCache::file() . PHP_EOL);
            echo $color->info("{$routes} entradas de rota compiladas" . PHP_EOL);
            return 0;
        } catch (Throwable $e) {
            echo $color->error($e->getMessage() . PHP_EOL . $e->getTraceAsString());
            return 1;
        }
    }

    /**
     * Todos os registros do mapa, qualquer que seja o balde.
     *
     * Esquecer um balde aqui faz um `#[RateLimit]` inexistente passar pelo
     * `route:cache` e voltar como 500 em produção.
     *
     * @return list<array<int|string,array<string,mixed>>>
     */
    private static function allRecords(array $map): array
    {
        $groups = [...array_values($map['static'] ?? []), ...array_values($map['dynamic'] ?? [])];

        foreach ($map['staticHost'] ?? [] as $byPath) {
            foreach ($byPath as $records) $groups[] = $records;
        }

        return $groups;
    }

    /**
     * @param array<string,mixed> $map
     * @return list<string>
     */
    private static function unknownPolicies(array $map): array
    {
        $declared = RateLimitConfig::from(Config::repository())->policies;
        $unknown = [];

        foreach (self::allRecords($map) as $records) {
            foreach ($records as $record) {
                $rateLimit = $record['rateLimit'] ?? null;
                if (!is_array($rateLimit)) continue;
                $policy = $rateLimit['policy'] ?? null;
                if (is_string($policy) && !isset($declared[$policy])) $unknown[$policy] = true;
            }
        }

        return array_keys($unknown);
    }
}
