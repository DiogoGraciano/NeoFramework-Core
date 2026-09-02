<?php
declare(strict_types=1);

namespace NeoFramework\Core\Http;

use Psr\Container\ContainerInterface;
use Psr\Http\Server\MiddlewareInterface;

final readonly class MiddlewareResolver
{
    public function __construct(private ContainerInterface $container) {}
    public function resolve(string|MiddlewareInterface $middleware): MiddlewareInterface
    {
        $resolved = is_string($middleware) ? $this->container->get($middleware) : $middleware;
        if (!$resolved instanceof MiddlewareInterface) throw new \LogicException('Middleware deve implementar PSR-15 MiddlewareInterface.');
        return $resolved;
    }
}
