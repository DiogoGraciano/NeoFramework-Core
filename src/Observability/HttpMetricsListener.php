<?php

declare(strict_types=1);

namespace NeoFramework\Core\Observability;

use NeoFramework\Core\Events\ExceptionRaised;
use NeoFramework\Core\Events\ResponseCreated;
use NeoFramework\Core\Http\RequestAttributes;
use NeoFramework\Core\Http\RequestScopeContext;
use NeoFramework\Core\Routing\RouteDefinition;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Traduz o ciclo HTTP em métricas.
 *
 * Monta sobre os eventos que já existiam desde a Fase 6 — foi para isso que o
 * dispatcher entrou antes das métricas. O kernel não sabe que isto existe.
 */
final readonly class HttpMetricsListener
{
    public const REQUESTS = 'http.server.requests';
    public const DURATION = 'http.server.duration_ms';
    public const EXCEPTIONS = 'http.server.exceptions';

    public function __construct(private MetricsExporterInterface $metrics)
    {
    }

    public function __invoke(object $event): void
    {
        match (true) {
            $event instanceof ResponseCreated => $this->onResponse($event),
            $event instanceof ExceptionRaised => $this->onException($event),
            default => null,
        };
    }

    private function onResponse(ResponseCreated $event): void
    {
        $labels = [
            'method' => $event->request->getMethod(),
            'route' => self::route($event->request),
            'status' => (string) $event->status(),
        ];

        $this->metrics->counter(self::REQUESTS, 1, $labels);
        $this->metrics->histogram(self::DURATION, $event->durationMs, $labels);
    }

    private function onException(ExceptionRaised $event): void
    {
        $this->metrics->counter(self::EXCEPTIONS, 1, [
            'route' => self::route($event->request),
            'status' => (string) $event->status,
            // A CLASSE da exceção é um conjunto fechado; a mensagem não é, e
            // costuma conter id ou valor do cliente.
            'type' => $event->exception::class,
        ]);
    }

    /**
     * O label é o **padrão** da rota, nunca o path resolvido.
     *
     * `/users/{id}` é um conjunto fechado; `/users/8123` é uma série temporal
     * nova por usuário. Confundir os dois é a forma clássica de derrubar um
     * backend de métricas com a própria instrumentação.
     *
     * A rota vem do escopo antes do request: `ResponseCreated` é despachado com
     * o request que o kernel recebeu, e o atributo que o `RoutingHandler` anexou
     * vive numa cópia — PSR-7 é imutável, então ele nunca sobe de volta. O
     * escopo é o que atravessa a requisição inteira.
     */
    private static function route(ServerRequestInterface $request): string
    {
        $route = RequestScopeContext::current()?->get(RequestAttributes::ROUTE)
            ?? $request->getAttribute(RequestAttributes::ROUTE);

        if (!$route instanceof RouteDefinition) return '<unmatched>';

        return $route->name ?? $route->path;
    }
}
