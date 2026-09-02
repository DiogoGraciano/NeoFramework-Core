<?php
declare(strict_types=1);

namespace NeoFramework\Core\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class Pipeline implements RequestHandlerInterface
{
    public function __construct(private array $middleware, private MiddlewareResolver $resolver, private RequestHandlerInterface $last, private int $index = 0) {}
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!isset($this->middleware[$this->index])) return $this->last->handle($request);
        return $this->resolver->resolve($this->middleware[$this->index])->process($request, new self($this->middleware, $this->resolver, $this->last, $this->index + 1));
    }
}
