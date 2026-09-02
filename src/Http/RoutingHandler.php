<?php
declare(strict_types=1);

namespace NeoFramework\Core\Http;

use NeoFramework\Core\Events\Events;
use NeoFramework\Core\Events\RouteMatched;
use NeoFramework\Core\Exceptions\MethodNotAllowedException;
use NeoFramework\Core\Exceptions\NotFoundException;
use NeoFramework\Core\Middleware\Csrf;
use NeoFramework\Core\Middleware\RateLimitMiddleware;
use NeoFramework\Core\RateLimit\FixedWindowRateLimiter;
use NeoFramework\Core\RateLimit\InMemoryRateLimitStore;
use NeoFramework\Core\RateLimit\RateLimiterInterface;
use NeoFramework\Core\RateLimit\RateLimitPolicyRegistry;
use NeoFramework\Core\Routing\Matcher;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class RoutingHandler implements RequestHandlerInterface
{
    public function __construct(private Matcher $matcher, private ContainerInterface $container, private MiddlewareResolver $resolver) {}
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = BasePath::strip($request->getUri()->getPath(), (string) $request->getAttribute(RequestAttributes::BASE_PATH, ''));
        // A URI PSR-7 monta o host a partir do header `Host`, que é escrito pelo
        // cliente. Uma rota restrita por `#[RouteHost]` só é uma fronteira de
        // verdade quando `http.trusted_hosts` está configurado ou o servidor da
        // frente recusa hosts desconhecidos — está documentado em ROUTING.md.
        $match = $this->matcher->match($request->getMethod(), $path, $request->getUri()->getHost());
        if ($match->status === 'not_found') throw new NotFoundException();
        if ($match->status === 'options') return (new \NeoFramework\Core\Response(204))->withHeader('Allow', implode(', ', $match->allowedMethods));
        if ($match->status === 'method_not_allowed') throw new MethodNotAllowedException($match->allowedMethods);
        $request = $request->withAttribute(RequestAttributes::ROUTE, $match->route)->withAttribute(RequestAttributes::ROUTE_VARIABLES, $match->variables);
        $scope = $request->getAttribute(RequestAttributes::SCOPE);
        if ($scope instanceof RequestScopeInterface) { $scope->set(ServerRequestInterface::class, $request); $scope->set(RequestAttributes::ROUTE, $match->route); $scope->set(RequestAttributes::ROUTE_VARIABLES, $match->variables); }
        Events::dispatch(new RouteMatched($request, $match->route, $match->variables));

        $rateLimit = $match->route->rateLimit === null ? [] : [new RateLimitMiddleware($this->rateLimiter(), $this->rateLimitPolicies())];

        return (new Pipeline([...$match->route->middleware, ...$rateLimit, Csrf::class], $this->resolver, new ActionHandler($match->route, $match->variables, $this->container)))->handle($request);
    }

    private function rateLimiter(): RateLimiterInterface
    {
        if ($this->container->has(RateLimiterInterface::class)) {
            $limiter = $this->container->get(RateLimiterInterface::class);
            if ($limiter instanceof RateLimiterInterface) return $limiter;
        }

        return new FixedWindowRateLimiter(new InMemoryRateLimitStore());
    }

    private function rateLimitPolicies(): RateLimitPolicyRegistry
    {
        if ($this->container->has(RateLimitPolicyRegistry::class)) {
            $policies = $this->container->get(RateLimitPolicyRegistry::class);
            if ($policies instanceof RateLimitPolicyRegistry) return $policies;
        }

        return new RateLimitPolicyRegistry();
    }
}
