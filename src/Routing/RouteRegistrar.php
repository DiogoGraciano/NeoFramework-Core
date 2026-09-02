<?php
declare(strict_types=1);

namespace NeoFramework\Core\Routing;

/**
 * Declaração programática de rotas, com grupos aninhados.
 *
 * Os atributos cobrem bem o caso comum, e param de cobrir quando o mesmo
 * conjunto de rotas precisa existir sob prefixos, hosts ou middlewares
 * diferentes — com atributo isso vira um controller duplicado por variação.
 * Aqui o grupo é um valor, e o mesmo bloco pode ser registrado duas vezes.
 *
 * ```php
 * $routes->group(['prefix' => '/api/v1', 'name' => 'api.v1.', 'middleware' => [Auth::class]], function (RouteRegistrar $r): void {
 *     $r->get('/users', [UserController::class, 'index'], name: 'users.index');
 * });
 * ```
 */
final class RouteRegistrar
{
    /** @var list<RouteDefinition> */
    private array $routes = [];

    /** @var array{prefix:string,name:string,middleware:list<class-string>,host:?string,priority:int,defaults:array<string,string>,csrf:bool} */
    private array $context = ['prefix' => '', 'name' => '', 'middleware' => [], 'host' => null, 'priority' => 0, 'defaults' => [], 'csrf' => true];

    /**
     * Abre um grupo. As chaves aceitas são as do contexto acima.
     *
     * `prefix` e `name` acumulam com os do grupo externo; `middleware` concatena;
     * `host`, `priority`, `defaults` e `csrf` sobrescrevem quando informados —
     * um grupo interno precisa poder sair do host do externo.
     *
     * @param array<string,mixed> $attributes
     * @param callable(self):void $routes
     */
    public function group(array $attributes, callable $routes): self
    {
        $previous = $this->context;

        $this->context = [
            'prefix' => $this->joinPrefix($previous['prefix'], (string) ($attributes['prefix'] ?? '')),
            'name' => $previous['name'] . (string) ($attributes['name'] ?? ''),
            'middleware' => [...$previous['middleware'], ...(array) ($attributes['middleware'] ?? [])],
            'host' => $attributes['host'] ?? $previous['host'],
            'priority' => (int) ($attributes['priority'] ?? $previous['priority']),
            'defaults' => [...$previous['defaults'], ...(array) ($attributes['defaults'] ?? [])],
            'csrf' => (bool) ($attributes['csrf'] ?? $previous['csrf']),
        ];

        try {
            $routes($this);
        } finally {
            // Restaurado mesmo se o callback lançar: sem isso o grupo seguinte
            // herdaria o prefixo do que falhou, e as rotas sairiam num lugar
            // que ninguém declarou.
            $this->context = $previous;
        }

        return $this;
    }

    /** @param array{0:class-string,1:string} $action */
    public function get(string $path, array $action, ?string $name = null, array $middleware = [], ?int $priority = null, array $defaults = []): self
    {
        return $this->route(['GET'], $path, $action, $name, $middleware, $priority, $defaults);
    }

    /** @param array{0:class-string,1:string} $action */
    public function post(string $path, array $action, ?string $name = null, array $middleware = [], ?int $priority = null, array $defaults = []): self
    {
        return $this->route(['POST'], $path, $action, $name, $middleware, $priority, $defaults);
    }

    /** @param array{0:class-string,1:string} $action */
    public function put(string $path, array $action, ?string $name = null, array $middleware = [], ?int $priority = null, array $defaults = []): self
    {
        return $this->route(['PUT'], $path, $action, $name, $middleware, $priority, $defaults);
    }

    /** @param array{0:class-string,1:string} $action */
    public function patch(string $path, array $action, ?string $name = null, array $middleware = [], ?int $priority = null, array $defaults = []): self
    {
        return $this->route(['PATCH'], $path, $action, $name, $middleware, $priority, $defaults);
    }

    /** @param array{0:class-string,1:string} $action */
    public function delete(string $path, array $action, ?string $name = null, array $middleware = [], ?int $priority = null, array $defaults = []): self
    {
        return $this->route(['DELETE'], $path, $action, $name, $middleware, $priority, $defaults);
    }

    /**
     * @param list<string> $methods
     * @param array{0:class-string,1:string} $action
     * @param list<class-string> $middleware
     * @param array<string,string> $defaults
     */
    public function route(array $methods, string $path, array $action, ?string $name = null, array $middleware = [], ?int $priority = null, array $defaults = []): self
    {
        [$controller, $method] = $action;

        if (!method_exists($controller, $method)) {
            // Uma action inexistente só falharia ao servir a rota, com 500.
            // Recusar no registro mantém a mesma promessa dos atributos.
            throw new \LogicException("A rota {$path} aponta para {$controller}::{$method}, que não existe.");
        }

        $this->routes[] = new RouteDefinition(
            $this->joinPath($this->context['prefix'], $path),
            array_map(strtoupper(...), $methods),
            $controller,
            $method,
            $name === null ? null : $this->context['name'] . $name,
            $this->context['csrf'],
            [...$this->context['middleware'], ...$middleware],
            null,
            $this->context['host'],
            $priority ?? $this->context['priority'],
            [...$this->context['defaults'], ...$defaults],
        );

        return $this;
    }

    /** @return list<RouteDefinition> */
    public function all(): array
    {
        return $this->routes;
    }

    public function toCollection(?RouteCollection $collection = null): RouteCollection
    {
        $collection ??= new RouteCollection();
        foreach ($this->routes as $route) $collection->add($route);

        return $collection;
    }

    private function joinPrefix(string $outer, string $inner): string
    {
        return rtrim($outer, '/') . ($inner === '' ? '' : '/' . trim($inner, '/'));
    }

    private function joinPath(string $prefix, string $path): string
    {
        if ($path === '' || $path[0] !== '/') throw new \InvalidArgumentException("O path da rota deve começar com '/': {$path}");
        $joined = preg_replace('~/+~', '/', '/' . trim($prefix, '/') . '/' . ltrim($path, '/')) ?: '/';

        return $joined !== '/' ? rtrim($joined, '/') : '/';
    }
}
