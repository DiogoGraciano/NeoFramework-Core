<?php
declare(strict_types=1);

namespace NeoFramework\Core\Routing;

final class RouteCollection
{
    private array $routes = [];
    private array $names = [];
    private array $signatures = [];
    /** @var array<string,RouteDefinition> */
    private array $fallbacks = [];

    public function add(RouteDefinition $route): void
    {
        if ($route->name !== null && isset($this->names[$route->name])) throw new \LogicException("Nome de rota duplicado: {$route->name}");
        foreach ($route->methods as $method) {
            $pattern = PatternCompiler::compile($route->path);
            // O host entra na assinatura: sem ele, duas rotas com o mesmo path
            // em hosts diferentes seriam recusadas como duplicata, que é
            // justamente o desenho que `#[RouteHost]` existe para permitir.
            $signature = strtoupper($method) . ' ' . ($route->host ?? '*') . ' ' . $pattern['regex'];
            if (isset($this->signatures[$signature])) throw new \LogicException("Rota duplicada: {$signature}");
            $this->signatures[$signature] = true;
        }
        if ($route->name !== null) $this->names[$route->name] = $route;
        $this->routes[] = $route;
    }
    public function all(): array { return $this->routes; }

    /**
     * Registra a action de fallback de um método HTTP.
     *
     * Dois fallbacks para o mesmo método é ambiguidade sem desempate possível:
     * um deles nunca rodaria, e descobrir qual exigiria ler a ordem de
     * descoberta dos controllers.
     */
    public function addFallback(RouteDefinition $route): void
    {
        foreach ($route->methods as $method) {
            $method = strtoupper($method);
            if (isset($this->fallbacks[$method])) throw new \LogicException("Já existe um fallback para {$method}.");
            $this->fallbacks[$method] = $route;
        }
    }

    /** @return array<string,RouteDefinition> */
    public function fallbacks(): array { return $this->fallbacks; }
    public function named(string $name): ?RouteDefinition { return $this->names[$name] ?? null; }
}
