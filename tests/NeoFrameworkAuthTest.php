<?php
declare(strict_types=1);

namespace Tests;

use DI\ContainerBuilder;
use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Attributes\Authenticated;
use NeoFramework\Core\Attributes\Authorize;
use NeoFramework\Core\Attributes\CurrentUser;
use NeoFramework\Core\Attributes\Route;
use NeoFramework\Core\Auth\Authorizer;
use NeoFramework\Core\Auth\BearerTokenGuard;
use NeoFramework\Core\Auth\GuardRegistry;
use NeoFramework\Core\Auth\IdentityInterface;
use NeoFramework\Core\Auth\InMemoryTokenRepository;
use NeoFramework\Core\Auth\PolicyRegistry;
use NeoFramework\Core\Auth\SessionGuard;
use NeoFramework\Core\Auth\SimpleIdentity;
use NeoFramework\Core\Auth\UserProviderInterface;
use NeoFramework\Core\Http\RequestScope;
use NeoFramework\Core\Http\RequestScopeContext;
use NeoFramework\Core\Request;
use NeoFramework\Core\Response;
use NeoFramework\Core\Session;
use NeoFramework\Core\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** Guarda os eventos vistos. Um objeto, porque desestruturar array perde a referência. */
final class EventRecorder
{
    /** @var list<object> */
    public array $events = [];

    public function __invoke(object $event): void
    {
        $this->events[] = $event;
    }
}

final class AuthUsersFixture implements UserProviderInterface
{
    /** @param array<string, IdentityInterface> $users */
    public function __construct(private array $users) {}
    public function findById(string $id): ?IdentityInterface { return $this->users[$id] ?? null; }
}

final class AuthControllerFixture extends Controller
{
    #[Route('/auth/me', ['GET'])]
    #[Authenticated]
    public function me(#[CurrentUser] IdentityInterface $user): Response
    {
        return $this->json(['id' => $user->id()]);
    }

    #[Route('/auth/users/{id}', ['PATCH'], false)]
    #[Authenticated]
    #[Authorize('users.update', subject: 'id')]
    public function update(string $id): Response
    {
        return $this->json(['updated' => $id]);
    }
}

final class ScopedAuthControllerFixture extends Controller
{
    #[Route('/auth/reports', ['GET'])]
    #[Authenticated('bearer', scopes: ['reports.read'])]
    public function reports(): Response
    {
        return $this->json(['ok' => true]);
    }
}

final class NeoFrameworkAuthTest extends TestCase
{
    public function testActingAsAuthenticatesAndInjectsTheCurrentUser(): void
    {
        TestClient::forControllers([AuthControllerFixture::class])
            ->actingAs(new SimpleIdentity('42'))
            ->getJson('/auth/me')
            ->assertOk()
            ->assertJsonPath('id', '42');
    }

    public function testAuthenticatedEndpointRejectsAnonymousRequests(): void
    {
        TestClient::forControllers([AuthControllerFixture::class])
            ->getJson('/auth/me')
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Bearer');
    }

    public function testPolicyCanAllowOrDenyARequestUsingRouteSubject(): void
    {
        $policies = new PolicyRegistry();
        $policies->register('users.update', static fn (IdentityInterface $user, mixed $subject): bool => $user->id() === $subject);
        $container = (new ContainerBuilder())->addDefinitions([
            GuardRegistry::class => new GuardRegistry(),
            Authorizer::class => new Authorizer($policies),
        ])->build();
        $client = TestClient::forControllers([AuthControllerFixture::class], container: $container)->actingAs(new SimpleIdentity('42'));

        $client->patchJson('/auth/users/42')->assertOk();
        $client->patchJson('/auth/users/7')->assertStatus(403);
    }

    public function testBearerTokensAreOpaqueScopedRevocableAndRefreshable(): void
    {
        $identity = new SimpleIdentity('42');
        $guard = new BearerTokenGuard(new InMemoryTokenRepository(), new AuthUsersFixture(['42' => $identity]));
        $issued = $guard->issue($identity, new \DateTimeImmutable('+1 hour'), ['reports.read']);
        $request = new Request('GET', '/', ['Authorization' => 'Bearer ' . $issued['token']]);
        $scope = new RequestScope();
        RequestScopeContext::enter($scope);
        try {
            self::assertSame($identity, $guard->authenticate($request));
            self::assertSame(['reports.read'], \NeoFramework\Core\Auth\AuthContext::current()->scopes);
            $refreshed = $guard->refresh($issued['token'], new \DateTimeImmutable('+2 hours'));
            self::assertNotNull($refreshed);
            self::assertNull($guard->authenticate($request));
            $guard->revoke($refreshed['token']);
            self::assertNull($guard->authenticate(new Request('GET', '/', ['Authorization' => 'Bearer ' . $refreshed['token']])));
        } finally {
            RequestScopeContext::leave($scope);
        }
    }

    public function testBearerScopesAreEnforcedByTheAuthenticatedAttribute(): void
    {
        $identity = new SimpleIdentity('42');
        $guard = new BearerTokenGuard(new InMemoryTokenRepository(), new AuthUsersFixture(['42' => $identity]));
        $container = (new ContainerBuilder())->addDefinitions([
            GuardRegistry::class => new GuardRegistry($guard),
        ])->build();
        $client = TestClient::forControllers([ScopedAuthControllerFixture::class], container: $container);
        $allowed = $guard->issue($identity, new \DateTimeImmutable('+1 hour'), ['reports.read']);
        $denied = $guard->issue($identity, new \DateTimeImmutable('+1 hour'));

        $client->withHeader('Authorization', 'Bearer ' . $allowed['token'])->getJson('/auth/reports')->assertOk();
        $client->withHeader('Authorization', 'Bearer ' . $denied['token'])->getJson('/auth/reports')->assertStatus(403);
    }

    public function testPoliciesAreUsableOutsideHttp(): void
    {
        $policies = new PolicyRegistry();
        $policies->register('reports.read', static fn (IdentityInterface $identity): bool => $identity->id() === 'allowed');
        $authorizer = new Authorizer($policies);

        $authorizer->authorize('reports.read', new SimpleIdentity('allowed'));
        $this->expectException(\NeoFramework\Core\Exceptions\ForbiddenException::class);
        $authorizer->authorize('reports.read', new SimpleIdentity('denied'));
    }

    public function testSessionLoginStoresOnlyTheIdentityAndLogoutInvalidatesIt(): void
    {
        $scope = new RequestScope();
        RequestScopeContext::enter($scope);
        try {
            $identity = new SimpleIdentity('42');
            $guard = new SessionGuard(new AuthUsersFixture(['42' => $identity]));
            $guard->login($identity);
            self::assertSame('42', Session::get('auth.session'));
            self::assertSame($identity, $guard->authenticate(new Request('GET', '/')));
            $guard->logout();
            self::assertNull(Session::get('auth.session'));
            self::assertFalse(\NeoFramework\Core\Auth\AuthContext::current()->check());
        } finally {
            RequestScopeContext::leave($scope);
        }
    }

    /** @return array{0:RequestScope,1:EventRecorder} */
    private function scopeRecording(array $events): array
    {
        $recorder = new EventRecorder();
        $provider = new \NeoFramework\Core\Events\ListenerProvider();
        foreach ($events as $event) $provider->on($event, $recorder);

        $scope = new RequestScope();
        $scope->set(\NeoFramework\Core\Events\Events::KEY, new \NeoFramework\Core\Events\EventDispatcher($provider));
        RequestScopeContext::enter($scope);

        return [$scope, $recorder];
    }

    public function testSessionLoginAndLogoutAreObservable(): void
    {
        [$scope, $recorder] = $this->scopeRecording([
            \NeoFramework\Core\Events\UserAuthenticated::class,
            \NeoFramework\Core\Events\UserLoggedOut::class,
        ]);

        try {
            $identity = new SimpleIdentity('42');
            $guard = new SessionGuard(new AuthUsersFixture(['42' => $identity]));

            $guard->login($identity);
            self::assertInstanceOf(\NeoFramework\Core\Events\UserAuthenticated::class, $recorder->events[0]);
            self::assertSame($identity, $recorder->events[0]->identity);
            self::assertSame('session', $recorder->events[0]->guard);

            $guard->logout();
            // A identidade é lida antes de invalidar a sessão: um listener de
            // auditoria precisa saber QUEM saiu, e depois do invalidate não há
            // mais de onde tirar isso.
            self::assertInstanceOf(\NeoFramework\Core\Events\UserLoggedOut::class, $recorder->events[1]);
            self::assertSame($identity, $recorder->events[1]->identity);
        } finally {
            RequestScopeContext::leave($scope);
        }
    }

    /** O evento leva identidade e guard — nunca a credencial que autenticou. */
    public function testBearerAuthenticationDoesNotPutTheTokenInTheEvent(): void
    {
        [$scope, $recorder] = $this->scopeRecording([\NeoFramework\Core\Events\UserAuthenticated::class]);

        try {
            $identity = new SimpleIdentity('42');
            $guard = new \NeoFramework\Core\Auth\BearerTokenGuard(
                new \NeoFramework\Core\Auth\InMemoryTokenRepository(),
                new AuthUsersFixture(['42' => $identity]),
            );
            $issued = $guard->issue($identity, new \DateTimeImmutable('+1 hour'));

            $guard->authenticate((new Request('GET', '/'))->withHeader('Authorization', 'Bearer ' . $issued['token']));

            self::assertCount(1, $recorder->events);
            self::assertSame($identity, $recorder->events[0]->identity);
            self::assertStringNotContainsString($issued['token'], json_encode($recorder->events[0], JSON_THROW_ON_ERROR));
        } finally {
            RequestScopeContext::leave($scope);
        }
    }
}
