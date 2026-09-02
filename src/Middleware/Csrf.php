<?php
declare(strict_types=1);

namespace NeoFramework\Core\Middleware;

use NeoFramework\Core\Http\RequestAttributes;
use NeoFramework\Core\Request;
use NeoFramework\Core\Response;
use NeoFramework\Core\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class Csrf implements MiddlewareInterface
{
    private const SAFE = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $route = $request->getAttribute(RequestAttributes::ROUTE);
        if (!$route?->csrf || in_array(strtoupper($request->getMethod()), self::SAFE, true)) return $handler->handle($request);
        $token = $request instanceof Request ? $request->getCsrfToken() : $request->getHeaderLine('X-CSRF-TOKEN');
        if (!Session::validateCsrfToken($token !== '' ? $token : null)) return (new Response(403))->text('Invalid or missing CSRF token.', 403);
        return $handler->handle($request);
    }
}
