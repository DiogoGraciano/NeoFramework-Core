<?php

declare(strict_types=1);

namespace NeoFramework\Core\Debug;

use NeoFramework\Core\Events\CacheAccessed;
use NeoFramework\Core\Events\ControllerInvoked;
use NeoFramework\Core\Events\QueryExecuted;
use NeoFramework\Core\Events\ResponseCreated;
use NeoFramework\Core\Http\RequestAttributes;
use NeoFramework\Core\Http\RequestScopeContext;
use NeoFramework\Core\Routing\RouteDefinition;

/**
 * Acumula os eventos de uma requisição num `Profile`.
 *
 * O estado vive na instância, e a instância é registrada por requisição — num
 * runtime persistente um coletor compartilhado somaria as consultas de todo
 * mundo e atribuiria a última requisição a contagem das anteriores.
 */
final class DebugCollector
{
    private float $controllerMs = 0.0;

    /** @var array<string,int> */
    private array $cache = ['hit' => 0, 'miss' => 0];

    /** @var array<string,array{count:int,durationMs:float}> */
    private array $queries = [];

    public function __construct(private readonly ProfileStore $store = new ProfileStore())
    {
    }

    public function __invoke(object $event): void
    {
        match (true) {
            $event instanceof ControllerInvoked => $this->controllerMs = $event->durationMs,
            $event instanceof CacheAccessed => $this->cache[$event->hit ? 'hit' : 'miss']++,
            $event instanceof QueryExecuted => $this->recordQuery($event),
            $event instanceof ResponseCreated => $this->finish($event),
            default => null,
        };
    }

    private function recordQuery(QueryExecuted $event): void
    {
        $operation = preg_match('/^\s*(select|insert|update|delete)\b/i', $event->sql, $match) === 1
            ? strtolower($match[1])
            : 'other';

        // Agrupado por operação, e não por SQL: o texto da consulta pode
        // carregar nome de tabela e coluna que a aplicação não quer num
        // endpoint de leitura, e a soma por operação é o que responde
        // "onde foi o tempo de banco".
        $this->queries[$operation] ??= ['count' => 0, 'durationMs' => 0.0];
        $this->queries[$operation]['count']++;
        $this->queries[$operation]['durationMs'] += $event->durationMs;
    }

    private function finish(ResponseCreated $event): void
    {
        $token = $event->response->getHeaderLine('X-Debug-Token');
        if ($token === '') return;

        $route = RequestScopeContext::current()?->get(RequestAttributes::ROUTE);

        $this->store->save(new Profile(
            $token,
            $event->request->getMethod(),
            $event->request->getUri()->getPath(),
            $route instanceof RouteDefinition ? ($route->name ?? $route->controller . '::' . $route->action) : null,
            $event->status(),
            $event->durationMs,
            $this->controllerMs,
            (int) round(memory_get_peak_usage(true) / 1024),
            $this->cache,
            $this->queries,
            microtime(true),
        ));
    }
}
