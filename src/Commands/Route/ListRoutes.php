<?php

declare(strict_types=1);

namespace NeoFramework\Core\Commands\Route;

use Ahc\Cli\Input\Command;
use Ahc\Cli\Output\Color;
use NeoFramework\Core\Commands\ExitCode;
use NeoFramework\Core\RouteCache;
use Throwable;

/**
 * Inspeção da tabela de rotas compilada.
 *
 * Existe porque o roteamento por atributo espalha a definição das rotas por
 * todos os controllers: sem um comando, a única forma de saber o que está
 * registrado é ler o código inteiro.
 */
class ListRoutes extends Command
{
    public function __construct()
    {
        parent::__construct('route:list', 'List the compiled routing table');

        $this->option('-j --json', 'Output as JSON, for tooling')
            ->option('-m --method [method]', 'Filter by HTTP method')
            ->option('-p --path [path]', 'Filter by path substring')
            ->version('1.0');
    }

    public function execute()
    {
        $color = new Color();

        try {
            $routes = self::flatten(RouteCache::build());
        } catch (Throwable $e) {
            fwrite(STDERR, $color->error($e->getMessage() . PHP_EOL));
            return ExitCode::FAILURE;
        }

        $routes = self::filter($routes, is_string($this->method) ? $this->method : null, is_string($this->path) ? $this->path : null);

        if ($this->json) {
            echo json_encode($routes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
            return ExitCode::OK;
        }

        if (!$routes) {
            echo $color->warn('Nenhuma rota encontrada.' . PHP_EOL);
            return ExitCode::OK;
        }

        self::table($routes, $color);
        echo PHP_EOL . $color->info(count($routes) . ' rota(s)' . PHP_EOL);

        return ExitCode::OK;
    }

    /**
     * Achata o mapa compilado numa lista ordenada, deduplicando pelo par
     * controller::action + path — uma rota com três verbos é uma linha, não três.
     *
     * @return list<array{methods:string,path:string,name:string,action:string,middleware:string,csrf:bool}>
     */
    private static function flatten(array $map): array
    {
        $rows = [];

        foreach (['static', 'dynamic'] as $kind) {
            foreach ($map[$kind] ?? [] as $method => $entries) {
                foreach ($entries as $record) self::collect($rows, $method, $record);
            }
        }

        // O balde de rota estática com host guarda uma LISTA por path, e não um
        // registro: percorrê-lo como os outros listaria arrays em vez de rotas.
        foreach ($map['staticHost'] ?? [] as $method => $byPath) {
            foreach ($byPath as $records) {
                foreach ($records as $record) self::collect($rows, $method, $record);
            }
        }

        $routes = [];
        foreach ($rows as $row) {
            // HEAD acompanha GET em toda rota; listar os dois só polui a tabela.
            $methods = array_values(array_diff(array_unique($row['methods']), ['HEAD']));
            sort($methods);
            $row['methods'] = implode('|', $methods);
            $row['middleware'] = implode(', ', $row['middleware']);
            $routes[] = $row;
        }

        usort($routes, static fn (array $a, array $b): int => [$a['path'], $a['methods']] <=> [$b['path'], $b['methods']]);

        return $routes;
    }

    /** @param list<array<string,mixed>> $routes @return list<array<string,mixed>> */
    private static function filter(array $routes, ?string $method, ?string $path): array
    {
        if ($method !== null && $method !== '') {
            $needle = strtoupper($method);
            $routes = array_filter($routes, static fn (array $r): bool => in_array($needle, explode('|', (string) $r['methods']), true));
        }

        if ($path !== null && $path !== '') {
            $routes = array_filter($routes, static fn (array $r): bool => str_contains((string) $r['path'], $path));
        }

        return array_values($routes);
    }

    /** @param list<array<string,mixed>> $routes */
    private static function table(array $routes, Color $color): void
    {
        $headers = ['METHOD', 'PATH', 'NAME', 'ACTION', 'MIDDLEWARE'];
        $keys = ['methods', 'path', 'name', 'action', 'middleware'];

        // A coluna HOST só aparece quando existe rota restrita: numa aplicação
        // sem `#[RouteHost]` ela seria uma coluna vazia em todas as linhas.
        if (array_filter($routes, static fn (array $r): bool => ($r['host'] ?? '') !== '') !== []) {
            array_splice($headers, 1, 0, ['HOST']);
            array_splice($keys, 1, 0, ['host']);
        }

        $widths = [];
        foreach ($keys as $i => $key) {
            $widths[$i] = max(strlen($headers[$i]), ...array_map(static fn (array $r): int => strlen((string) $r[$key]), $routes));
        }

        $line = '';
        foreach ($headers as $i => $header) {
            $line .= str_pad($header, $widths[$i] + 2);
        }
        echo $color->info(rtrim($line) . PHP_EOL);

        foreach ($routes as $route) {
            $line = '';
            foreach ($keys as $i => $key) {
                $line .= str_pad((string) $route[$key], $widths[$i] + 2);
            }
            echo rtrim($line) . PHP_EOL;
        }
    }

    /**
     * Acumula um registro na linha do par action + path + host.
     *
     * O host entra na chave: duas rotas de hosts diferentes com o mesmo path
     * são rotas distintas, e uni-las esconderia uma delas da listagem.
     *
     * @param array<string,array<string,mixed>> $rows
     * @param array<string,mixed> $record
     */
    private static function collect(array &$rows, string $method, array $record): void
    {
        $host = $record['host'] ?? null;
        $key = $record['controller'] . '::' . $record['action'] . ' ' . ($host ?? '') . $record['path'];

        $rows[$key] ??= [
            'methods' => [],
            'host' => $host ?? '',
            'path' => $record['path'],
            'name' => $record['name'] ?? '',
            'action' => self::shortClass($record['controller']) . '::' . $record['action'],
            'middleware' => array_map(self::shortClass(...), $record['middleware'] ?? []),
            'csrf' => (bool) ($record['csrf'] ?? true),
        ];
        $rows[$key]['methods'][] = $method;
    }

    private static function shortClass(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
