<?php
declare(strict_types=1);

namespace NeoFramework\Core\Middleware;

use NeoFramework\Core\Config;
use NeoFramework\Core\Events\Events;
use NeoFramework\Core\Events\ExceptionRaised;
use NeoFramework\Core\Exceptions\HttpException;
use NeoFramework\Core\Exceptions\ValidationException;
use NeoFramework\Core\Http\RequestAttributes;
use NeoFramework\Core\Http\RequestScopeInterface;
use NeoFramework\Core\Logger;
use NeoFramework\Core\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

final class ErrorHandler implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try { return $handler->handle($request); }
        catch (Throwable $error) {
            $status = $error instanceof HttpException ? $error->status : 500;
            $headers = $error instanceof HttpException ? $error->headers : [];
            Events::dispatch(new ExceptionRaised($request, $error, $status));
            if ($status === 500) Logger::error($error->getMessage() . ' ' . $error->getTraceAsString());
            $message = $status === 500 && Config::get('app.environment', 'dev') === 'prod' ? 'Internal Server Error' : $error->getMessage();
            $response = new Response($status, $headers);
            if (str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json')) {
                $scope = $request->getAttribute(RequestAttributes::SCOPE);
                $problem = ['type' => $error instanceof ValidationException ? 'https://neoframework.dev/problems/validation' : 'about:blank', 'title' => $message, 'status' => $status, 'instance' => $request->getUri()->getPath()];
                if ($scope instanceof RequestScopeInterface) $problem['requestId'] = $scope->get('neoframework.request_id');
                if ($error instanceof ValidationException) $problem['errors'] = $error->errors;
                return $response->json($problem, $status)->withHeader('Content-Type', 'application/problem+json');
            }
            return $response->text($message, $status);
        }
    }
}
