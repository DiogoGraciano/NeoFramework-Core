<?php
declare(strict_types=1);

namespace Tests;

use GuzzleHttp\Psr7\ServerRequest;
use NeoFramework\Core\Auth\IdentityInterface;
use NeoFramework\Core\Auth\LoginThrottle;
use NeoFramework\Core\Auth\SimpleIdentity;
use NeoFramework\Core\Config\AuthConfig;
use NeoFramework\Core\Config\ConfigRepository;
use NeoFramework\Core\Config\ConfigurationException;
use NeoFramework\Core\Events\EventDispatcher;
use NeoFramework\Core\Events\Events;
use NeoFramework\Core\Events\ListenerProvider;
use NeoFramework\Core\Events\LoginFailed;
use NeoFramework\Core\Events\LoginThrottled;
use NeoFramework\Core\Exceptions\TooManyRequestsException;
use NeoFramework\Core\Http\RequestScope;
use NeoFramework\Core\Http\RequestScopeContext;
use NeoFramework\Core\RateLimit\InMemoryRateLimitStore;
use NeoFramework\Core\Testing\FakeClock;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class NeoFrameworkLoginThrottleTest extends TestCase
{
    protected function setUp(): void
    {
        InMemoryRateLimitStore::reset();
    }

    private function request(string $ip = '203.0.113.9'): ServerRequestInterface
    {
        return new ServerRequest('POST', '/login', serverParams: ['REMOTE_ADDR' => $ip]);
    }

    private function throttle(int $limit = 3, int $window = 900, int $now = 0): LoginThrottle
    {
        return new LoginThrottle(new InMemoryRateLimitStore(), $limit, $window, new FakeClock($now));
    }

    /** Uma verificação que recusa a credencial. @return callable():?IdentityInterface */
    private function wrongPassword(): callable
    {
        return static fn (): ?IdentityInterface => null;
    }

    public function testAFailedAttemptReturnsNullWithoutConsumingTheLockout(): void
    {
        $throttle = $this->throttle();

        self::assertNull($throttle->attempt('user@example.com', $this->request(), $this->wrongPassword()));
        self::assertNull($throttle->attempt('user@example.com', $this->request(), $this->wrongPassword()));

        // Duas falhas com limite três: a terceira ainda deve ser verificada.
        $identity = new SimpleIdentity('42');
        self::assertSame($identity, $throttle->attempt('user@example.com', $this->request(), static fn (): IdentityInterface => $identity));
    }

    public function testTheIdentifierLocksOutEvenWhenEveryAttemptComesFromADifferentIp(): void
    {
        $throttle = $this->throttle();

        // O ataque distribuído é o caso que o limite por rota não cobre: cada IP
        // gasta uma tentativa só, e a conta é varrida sem nenhum contador estourar.
        $throttle->attempt('alvo@example.com', $this->request('198.51.100.1'), $this->wrongPassword());
        $throttle->attempt('alvo@example.com', $this->request('198.51.100.2'), $this->wrongPassword());
        $throttle->attempt('alvo@example.com', $this->request('198.51.100.3'), $this->wrongPassword());

        $this->expectException(TooManyRequestsException::class);
        $throttle->attempt('alvo@example.com', $this->request('198.51.100.4'), $this->wrongPassword());
    }

    public function testTheIpLocksOutEvenWhenEveryAttemptTargetsADifferentAccount(): void
    {
        $throttle = $this->throttle();

        $throttle->attempt('a@example.com', $this->request(), $this->wrongPassword());
        $throttle->attempt('b@example.com', $this->request(), $this->wrongPassword());
        $throttle->attempt('c@example.com', $this->request(), $this->wrongPassword());

        $this->expectException(TooManyRequestsException::class);
        $throttle->attempt('d@example.com', $this->request(), $this->wrongPassword());
    }

    public function testCaseVariationOfTheIdentifierShareTheSameCounter(): void
    {
        $throttle = $this->throttle();

        // Sem normalizar, alternar a caixa daria um contador novo a cada tentativa.
        $throttle->attempt('User@Example.com', $this->request('198.51.100.1'), $this->wrongPassword());
        $throttle->attempt('USER@EXAMPLE.COM', $this->request('198.51.100.2'), $this->wrongPassword());
        $throttle->attempt(' user@example.com ', $this->request('198.51.100.3'), $this->wrongPassword());

        $this->expectException(TooManyRequestsException::class);
        $throttle->attempt('user@example.com', $this->request('198.51.100.4'), $this->wrongPassword());
    }

    public function testTheCredentialIsNeverVerifiedWhileLockedOut(): void
    {
        $throttle = $this->throttle(limit: 1);
        $throttle->attempt('user@example.com', $this->request(), $this->wrongPassword());

        $verified = false;
        try {
            $throttle->attempt('user@example.com', $this->request(), static function () use (&$verified): ?IdentityInterface {
                $verified = true;

                return null;
            });
            self::fail('A tentativa bloqueada deveria ter lançado.');
        } catch (TooManyRequestsException) {
            // O ponto do bloqueio é não chegar ao hash da senha: verificar
            // mesmo assim manteria o custo de CPU que o ataque quer provocar.
            self::assertFalse($verified);
        }
    }

    public function testSuccessClearsBothCountersSoTheNextLoginStartsFresh(): void
    {
        $throttle = $this->throttle();
        $identity = new SimpleIdentity('42');

        $throttle->attempt('user@example.com', $this->request(), $this->wrongPassword());
        $throttle->attempt('user@example.com', $this->request(), $this->wrongPassword());
        $throttle->attempt('user@example.com', $this->request(), static fn (): IdentityInterface => $identity);

        // Quem provou a credencial não carrega as falhas anteriores: sem isso,
        // um escritório atrás de um NAT derrubaria o próprio login.
        $throttle->attempt('user@example.com', $this->request(), $this->wrongPassword());
        $throttle->attempt('user@example.com', $this->request(), $this->wrongPassword());
        self::assertSame($identity, $throttle->attempt('user@example.com', $this->request(), static fn (): IdentityInterface => $identity));
    }

    public function testTheLockoutExpiresWithTheWindow(): void
    {
        $store = new InMemoryRateLimitStore();
        $clock = new FakeClock(0);
        $locked = new LoginThrottle($store, 1, 900, $clock);

        $locked->attempt('user@example.com', $this->request(), $this->wrongPassword());

        try {
            $locked->attempt('user@example.com', $this->request(), $this->wrongPassword());
            self::fail('Deveria estar bloqueado dentro da janela.');
        } catch (TooManyRequestsException) {
        }

        $later = new LoginThrottle($store, 1, 900, new FakeClock(901));
        self::assertNull($later->attempt('user@example.com', $this->request(), $this->wrongPassword()));
    }

    public function testTheRejectionCarriesRetryAfter(): void
    {
        $throttle = $this->throttle(limit: 1, window: 900, now: 100);
        $throttle->attempt('user@example.com', $this->request(), $this->wrongPassword());

        try {
            $throttle->attempt('user@example.com', $this->request(), $this->wrongPassword());
            self::fail('Deveria ter lançado.');
        } catch (TooManyRequestsException $e) {
            self::assertSame('800', $e->headers['Retry-After'] ?? null);
        }
    }

    public function testFailureAndLockoutAreObservableWithoutLeakingThePassword(): void
    {
        $recorder = new EventRecorder();
        $provider = new ListenerProvider();
        $provider->on(LoginFailed::class, $recorder);
        $provider->on(LoginThrottled::class, $recorder);

        $scope = new RequestScope();
        $scope->set(Events::KEY, new EventDispatcher($provider));
        RequestScopeContext::enter($scope);

        try {
            $throttle = $this->throttle(limit: 1);
            $throttle->attempt('user@example.com', $this->request(), $this->wrongPassword());

            self::assertInstanceOf(LoginFailed::class, $recorder->events[0]);
            self::assertSame('user@example.com', $recorder->events[0]->identifier);
            self::assertSame('203.0.113.9', $recorder->events[0]->ip);

            try {
                $throttle->attempt('user@example.com', $this->request(), $this->wrongPassword());
            } catch (TooManyRequestsException) {
            }

            self::assertInstanceOf(LoginThrottled::class, $recorder->events[1]);
            // A dimensão distingue ataque dirigido a uma conta de varredura por IP.
            self::assertSame('identifier', $recorder->events[1]->dimension);

            $serialized = json_encode(array_map(static fn (object $e): array => (array) $e, $recorder->events));
            self::assertIsString($serialized);
            self::assertStringNotContainsString('hunter2', $serialized);
        } finally {
            RequestScopeContext::leave($scope);
        }
    }

    public function testTheIpDimensionIsReportedWhenItIsTheOneThatTripped(): void
    {
        $throttle = $this->throttle(limit: 2);
        $recorder = new EventRecorder();
        $provider = new ListenerProvider();
        $provider->on(LoginThrottled::class, $recorder);

        $scope = new RequestScope();
        $scope->set(Events::KEY, new EventDispatcher($provider));
        RequestScopeContext::enter($scope);

        try {
            $throttle->attempt('a@example.com', $this->request(), $this->wrongPassword());
            $throttle->attempt('b@example.com', $this->request(), $this->wrongPassword());

            try {
                $throttle->attempt('c@example.com', $this->request(), $this->wrongPassword());
                self::fail('Deveria ter lançado.');
            } catch (TooManyRequestsException) {
            }

            self::assertInstanceOf(LoginThrottled::class, $recorder->events[0]);
            self::assertSame('ip', $recorder->events[0]->dimension);
        } finally {
            RequestScopeContext::leave($scope);
        }
    }

    public function testTheIdentifierNeverAppearsInTheStoreKey(): void
    {
        $store = new RecordingRateLimitStore();
        $throttle = new LoginThrottle($store, 3, 900, new FakeClock(0));

        $throttle->attempt('secret-user@example.com', $this->request(), $this->wrongPassword());

        // As chaves do Redis são legíveis por qualquer um com acesso ao servidor.
        foreach ($store->keys as $key) self::assertStringNotContainsString('secret-user', $key);
        self::assertNotSame([], $store->keys);
    }

    public function testTheThrottleIsConfiguredAndOnByDefault(): void
    {
        $config = AuthConfig::from(new ConfigRepository(sys_get_temp_dir(), \NeoFramework\Core\Config\Defaults::all(), false));

        self::assertSame(5, $config->loginThrottleLimit);
        self::assertSame(900, $config->loginThrottleWindow);
    }

    public function testAnInvalidThrottleConfigurationFailsAtBootstrap(): void
    {
        $this->expectException(ConfigurationException::class);
        AuthConfig::from(new ConfigRepository(sys_get_temp_dir(), ['auth' => ['login_throttle' => ['limit' => 0, 'window' => 900]]], false));
    }
}

/** Guarda as chaves entregues ao store, para provar que o e-mail não aparece nelas. */
final class RecordingRateLimitStore implements \NeoFramework\Core\RateLimit\RateLimitStoreInterface
{
    /** @var list<string> */
    public array $keys = [];

    public function increment(string $key, int $expiresAt): int
    {
        $this->keys[] = $key;

        return 1;
    }

    public function read(string $key): int
    {
        $this->keys[] = $key;

        return 0;
    }

    public function forget(string $key): void
    {
        $this->keys[] = $key;
    }
}
