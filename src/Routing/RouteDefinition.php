<?php
declare(strict_types=1);

namespace NeoFramework\Core\Routing;

final readonly class RouteDefinition
{
    /**
     * @param list<string> $methods
     * @param list<class-string> $middleware
     * @param array{limit?:int,window?:int,key?:string,policy?:string}|null $rateLimit
     * @param string|null $host Padrão de host; null casa qualquer host.
     * @param int $priority Maior roda antes. Empate resolve pela ordem de declaração.
     * @param array<string,string> $defaults Valores para variáveis ausentes na URL.
     */
    public function __construct(
        public string $path,
        public array $methods,
        public string $controller,
        public string $action,
        public ?string $name = null,
        public bool $csrf = true,
        public array $middleware = [],
        public ?array $rateLimit = null,
        public ?string $host = null,
        public int $priority = 0,
        public array $defaults = [],
    ) {}

    public function toArray(): array { return get_object_vars($this); }

    /** @param array<string,mixed> $route */
    public static function fromArray(array $route): self
    {
        return new self(
            $route['path'],
            $route['methods'],
            $route['controller'],
            $route['action'],
            $route['name'],
            $route['csrf'],
            $route['middleware'],
            $route['rateLimit'] ?? null,
            $route['host'] ?? null,
            $route['priority'] ?? 0,
            $route['defaults'] ?? [],
        );
    }
}
