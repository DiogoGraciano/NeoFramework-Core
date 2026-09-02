<?php
declare(strict_types=1);

namespace NeoFramework\Core\Http;

use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Attributes\Authenticated;
use NeoFramework\Core\Attributes\Authorize;
use NeoFramework\Core\Auth\AuthContext;
use NeoFramework\Core\Auth\Authorizer;
use NeoFramework\Core\Auth\BearerTokenGuard;
use NeoFramework\Core\Auth\GuardRegistry;
use NeoFramework\Core\Auth\InMemoryTokenRepository;
use NeoFramework\Core\Auth\NullUserProvider;
use NeoFramework\Core\Auth\PolicyRegistry;
use NeoFramework\Core\Auth\SessionGuard;
use NeoFramework\Core\Events\ControllerInvoked;
use NeoFramework\Core\Events\ControllerInvoking;
use NeoFramework\Core\Events\Events;
use NeoFramework\Core\Exceptions\UnauthorizedException;
use NeoFramework\Core\Response;
use NeoFramework\Core\Routing\RouteDefinition;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionMethod;

final readonly class ActionHandler implements RequestHandlerInterface
{
    public function __construct(private RouteDefinition $route, private array $variables, private ContainerInterface $container) {}
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $controller = $this->container->get($this->route->controller);
        if (!$controller instanceof Controller) throw new \LogicException("{$this->route->controller} deve estender Controller.");
        $controller->setRequest($request)->setResponse(new Response());
        $method = new ReflectionMethod($controller, $this->route->action);
        $authentication = $this->authentication($method);
        $authorizations = [...$method->getDeclaringClass()->getAttributes(Authorize::class), ...$method->getAttributes(Authorize::class)];
        $identity = null;
        if ($authentication !== null || $authorizations !== []) {
            $guard = $authentication instanceof Authenticated ? $authentication->guard : 'session';
            $identity = $this->guards()->authenticate($request, $guard);
            if ($identity === null) throw new UnauthorizedException();
            if ($authentication !== null && array_diff($authentication->scopes, AuthContext::current()->scopes) !== []) {
                throw new \NeoFramework\Core\Exceptions\ForbiddenException('Insufficient token scope');
            }
        }
        $arguments = ArgumentResolver::resolve($method, $this->variables, $request, $this->container);
        if ($authorizations !== []) {
            $byName = [];
            foreach ($method->getParameters() as $index => $parameter) $byName[$parameter->getName()] = $arguments[$index];
            foreach ($authorizations as $attribute) {
                $rule = $attribute->newInstance();
                $subject = $rule->subject === null ? null : ($byName[$rule->subject] ?? $this->variables[$rule->subject] ?? null);
                $this->authorizer()->authorize($rule->ability, $identity, $subject);
            }
        }
        Events::dispatch(new ControllerInvoking($request, $this->route));
        $startedAt = hrtime(true);

        $result = $method->invokeArgs($controller, $arguments);

        // Só depois de retornar: se a action lançar, o evento do ciclo é
        // `ExceptionRaised`. Anunciar "invoked" com a duração de um trabalho
        // que não terminou poluiria qualquer média de latência.
        Events::dispatch(new ControllerInvoked($request, $this->route, (hrtime(true) - $startedAt) / 1_000_000));

        return ResponseNormalizer::normalize($result, $controller);
    }

    private function authentication(ReflectionMethod $method): ?Authenticated
    {
        $attributes = $method->getAttributes(Authenticated::class);
        if ($attributes !== []) return $attributes[0]->newInstance();
        $attributes = $method->getDeclaringClass()->getAttributes(Authenticated::class);
        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    private function guards(): GuardRegistry
    {
        if ($this->container->has(GuardRegistry::class)) {
            try {
                $registry = $this->container->get(GuardRegistry::class);
                if ($registry instanceof GuardRegistry) return $registry;
            } catch (\Throwable) {
                // Um container de teste sem definições de auth usa os guards
                // mínimos abaixo; produção registra GuardRegistry explicitamente.
            }
        }
        $users = new NullUserProvider();
        return new GuardRegistry(new SessionGuard($users), new BearerTokenGuard(new InMemoryTokenRepository(), $users));
    }

    private function authorizer(): Authorizer
    {
        if ($this->container->has(Authorizer::class)) {
            try {
                $authorizer = $this->container->get(Authorizer::class);
                if ($authorizer instanceof Authorizer) return $authorizer;
            } catch (\Throwable) {
            }
        }
        return new Authorizer(new PolicyRegistry());
    }
}
