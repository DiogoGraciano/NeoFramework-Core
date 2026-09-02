<?php
declare(strict_types=1);

namespace NeoFramework\Core;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** PSR-15 front handler. Prefer HttpKernel when middleware is required. */
final class Router implements RequestHandlerInterface
{
    private HttpKernel $kernel;
    public function __construct(?HttpKernel $kernel = null) { $this->kernel = $kernel ?? HttpKernel::create(); }
    public function handle(ServerRequestInterface $request): ResponseInterface { return $this->kernel->handle($request); }
    public function load(?ServerRequestInterface $request = null): ResponseInterface { return $this->handle($request ?? Request::fromGlobals()); }
}
