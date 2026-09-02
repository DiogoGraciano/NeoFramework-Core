<?php
declare(strict_types=1);

namespace NeoFramework\Core\Middleware;

use NeoFramework\Core\Exceptions\TooManyRequestsException;
use NeoFramework\Core\Http\RequestAttributes;
use NeoFramework\Core\RateLimit\RateLimiterInterface;
use NeoFramework\Core\RateLimit\RateLimitPolicyRegistry;
use NeoFramework\Core\Routing\RouteDefinition;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(private RateLimiterInterface $limiter, private RateLimitPolicyRegistry $policies = new RateLimitPolicyRegistry()) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $route = $request->getAttribute(RequestAttributes::ROUTE);
        if (!$route instanceof RouteDefinition || $route->rateLimit === null) return $handler->handle($request);

        $policy = $this->policies->resolve($route->rateLimit);
        $key = $policy->key->valueFor($request);
        $result = $this->limiter->attempt('route:' . ($route->name ?? $route->path) . ':' . $key, $policy->limit, $policy->window);
        $headers = [
            'RateLimit-Limit' => (string) $result->limit,
            'RateLimit-Remaining' => (string) $result->remaining,
            'RateLimit-Reset' => (string) $result->resetAt,
        ];
        if (!$result->allowed) {
            $headers['Retry-After'] = (string) $result->retryAfter;
            throw new TooManyRequestsException($headers);
        }

        return $handler->handle($request)->withAddedHeader('RateLimit-Limit', $headers['RateLimit-Limit'])
            ->withAddedHeader('RateLimit-Remaining', $headers['RateLimit-Remaining'])
            ->withAddedHeader('RateLimit-Reset', $headers['RateLimit-Reset']);
    }
}
