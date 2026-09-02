<?php
declare(strict_types=1);

namespace Tests;

use DI\ContainerBuilder;
use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Attributes\RateLimit;
use NeoFramework\Core\Attributes\Route;
use NeoFramework\Core\Cache;
use NeoFramework\Core\Commands\Route\Cache as RouteCacheCommand;
use NeoFramework\Core\Config\ConfigRepository;
use NeoFramework\Core\Config\ConfigurationException;
use NeoFramework\Core\Config\RateLimitConfig;
use NeoFramework\Core\RateLimit\CacheRateLimitStore;
use NeoFramework\Core\RateLimit\FixedWindowRateLimiter;
use NeoFramework\Core\RateLimit\InMemoryRateLimitStore;
use NeoFramework\Core\RateLimit\RateLimiterFactory;
use NeoFramework\Core\RateLimit\RateLimiterInterface;
use NeoFramework\Core\RateLimit\RateLimitFailureStrategy;
use NeoFramework\Core\RateLimit\RateLimitKey;
use NeoFramework\Core\RateLimit\RateLimitPolicy;
use NeoFramework\Core\RateLimit\RateLimitPolicyRegistry;
use NeoFramework\Core\RateLimit\RateLimitStoreInterface;
use NeoFramework\Core\RateLimit\RedisRateLimitStore;
use NeoFramework\Core\RateLimit\ResilientRateLimiter;
use NeoFramework\Core\RateLimit\SlidingWindowRateLimiter;
use NeoFramework\Core\Response;
use NeoFramework\Core\Routing\AttributeLoader;
use NeoFramework\Core\Routing\RouteCompiler;
use NeoFramework\Core\Testing\FakeClock;
use NeoFramework\Core\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class RateLimitControllerFixture extends Controller
{
    #[Route('/limited', ['GET'])]
    #[RateLimit(limit: 2, window: 60, key: RateLimitKey::Ip)]
    public function index(): Response { return $this->json(['ok' => true]); }

    #[Route('/by-policy', ['GET'])]
    #[RateLimit(policy: 'login')]
    public function byPolicy(): Response { return $this->json(['ok' => true]); }

    /** `login` passou a existir por padrão; o caso de política inexistente precisa de um nome que não exista. */
    #[Route('/by-unknown-policy', ['GET'])]
    #[RateLimit(policy: 'nao-declarada')]
    public function byUnknownPolicy(): Response { return $this->json(['ok' => true]); }
}

/** Captura o que o limitador registra quando o backend cai. */
final class RecordingRateLimitLogger extends \Psr\Log\AbstractLogger
{
    /** @var list<array{level:mixed,message:string,context:array<string,mixed>}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}

/** Backend indisponível: é o que o Redis fora do ar entrega ao limitador. */
final class BrokenRateLimitStore implements RateLimitStoreInterface
{
    public function increment(string $key, int $expiresAt): int { throw new \RuntimeException('backend down'); }
    public function read(string $key): int { throw new \RuntimeException('backend down'); }
    public function forget(string $key): void { throw new \RuntimeException('backend down'); }
}

final class NeoFrameworkRateLimitTest extends TestCase
{
    protected function setUp(): void { InMemoryRateLimitStore::reset(); }

    public function testFixedWindowResetsUsingAnInjectedClock(): void
    {
        $clock = new FakeClock(120);
        $limiter = new FixedWindowRateLimiter(new InMemoryRateLimitStore(), $clock);

        self::assertTrue($limiter->attempt('client', 1, 60)->allowed);
        self::assertFalse($limiter->attempt('client', 1, 60)->allowed);
        $clock->set(180);
        self::assertTrue($limiter->attempt('client', 1, 60)->allowed);
    }

    public function testAttributeBlocksTheThirdRequestAndEmitsStandardHeaders(): void
    {
        $clock = new FakeClock(120);
        $container = (new ContainerBuilder())->addDefinitions([
            RateLimiterInterface::class => new FixedWindowRateLimiter(new InMemoryRateLimitStore(), $clock),
        ])->build();
        $client = TestClient::forControllers([RateLimitControllerFixture::class], container: $container)
            ->withServerParams(['REMOTE_ADDR' => '203.0.113.8']);

        $client->getJson('/limited')
            ->assertOk()
            ->assertHeader('RateLimit-Limit', '2')
            ->assertHeader('RateLimit-Remaining', '1')
            ->assertHeader('RateLimit-Reset', '180');
        $client->getJson('/limited')->assertOk()->assertHeader('RateLimit-Remaining', '0');
        $client->getJson('/limited')
            ->assertStatus(429)
            ->assertContentType('application/problem+json')
            ->assertHeader('RateLimit-Remaining', '0')
            ->assertHeader('Retry-After', '60');
    }

    public function testNamedPolicyFromConfigurationDrivesTheAttribute(): void
    {
        $clock = new FakeClock(120);
        $container = (new ContainerBuilder())->addDefinitions([
            RateLimiterInterface::class => new FixedWindowRateLimiter(new InMemoryRateLimitStore(), $clock),
            RateLimitPolicyRegistry::class => new RateLimitPolicyRegistry(['login' => new RateLimitPolicy(1, 60, RateLimitKey::Ip)]),
        ])->build();
        $client = TestClient::forControllers([RateLimitControllerFixture::class], container: $container)
            ->withServerParams(['REMOTE_ADDR' => '203.0.113.9']);

        $client->getJson('/by-policy')->assertOk()->assertHeader('RateLimit-Limit', '1');
        $client->getJson('/by-policy')->assertStatus(429);
    }

    public function testUnknownNamedPolicyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RateLimitPolicyRegistry())->resolve(['policy' => 'ghost']);
    }

    public function testSlidingWindowRefusesTheBurstThatAFixedWindowAllowsOnTheBoundary(): void
    {
        $clock = new FakeClock(119);
        $fixed = new FixedWindowRateLimiter(new InMemoryRateLimitStore(), $clock);
        $sliding = new SlidingWindowRateLimiter(new InMemoryRateLimitStore(), $clock);

        // Gasta a cota no último segundo da janela que termina em 120.
        foreach (['fixed' => $fixed, 'sliding' => $sliding] as $key => $limiter) {
            self::assertTrue($limiter->attempt($key, 2, 60)->allowed);
            self::assertTrue($limiter->attempt($key, 2, 60)->allowed);
        }

        // Um segundo depois a janela virou: a fixa devolve a cota inteira, o
        // que deixa o cliente fazer 4 requisições em 2 segundos; a deslizante
        // ainda carrega 59/60 da janela anterior.
        $clock->set(120);
        self::assertTrue($fixed->attempt('fixed', 2, 60)->allowed);
        self::assertFalse($sliding->attempt('sliding', 2, 60)->allowed);
    }

    public function testSlidingWindowReleasesQuotaAsThePreviousWindowAgesOut(): void
    {
        $clock = new FakeClock(100);
        $limiter = new SlidingWindowRateLimiter(new InMemoryRateLimitStore(), $clock);

        self::assertTrue($limiter->attempt('client', 2, 60)->allowed);
        self::assertTrue($limiter->attempt('client', 2, 60)->allowed);

        // 55s depois só 5/60 da janela anterior ainda pesa: 1 de 2 usados.
        $clock->set(175);
        $result = $limiter->attempt('client', 2, 60);
        self::assertTrue($result->allowed);
        self::assertSame(0, $result->remaining);
        self::assertSame(180, $result->resetAt);
    }

    public function testFailOpenLetsTrafficThroughAndFailClosedRejectsIt(): void
    {
        $clock = new FakeClock(120);
        $broken = new FixedWindowRateLimiter(new BrokenRateLimitStore(), $clock);

        $open = (new ResilientRateLimiter($broken, RateLimitFailureStrategy::Open, clock: $clock))->attempt('client', 5, 60);
        self::assertTrue($open->allowed);
        self::assertSame(5, $open->remaining);

        $closed = (new ResilientRateLimiter($broken, RateLimitFailureStrategy::Closed, clock: $clock))->attempt('client', 5, 60);
        self::assertFalse($closed->allowed);
        self::assertSame(60, $closed->retryAfter);
    }

    public function testABrokenBackendDoesNotLeakTheRateLimitKeyIntoTheLog(): void
    {
        $logger = new RecordingRateLimitLogger();
        $limiter = new ResilientRateLimiter(new FixedWindowRateLimiter(new BrokenRateLimitStore()), RateLimitFailureStrategy::Open, $logger);

        $limiter->attempt('route:login:ip:203.0.113.10', 5, 60);

        self::assertCount(1, $logger->records);
        self::assertStringNotContainsString('203.0.113.10', json_encode($logger->records, JSON_THROW_ON_ERROR));
    }

    public function testCacheStoreAcceptsKeysWithCharactersReservedByPsr6(): void
    {
        // `route:/limited:ip:203.0.113.8:180` contém `:` e `/`, recusados pelo
        // PSR-6. Sem normalização o binding padrão estoura em toda requisição.
        $store = new CacheRateLimitStore();
        $key = 'route:/limited:ip:203.0.113.8:' . random_int(1, PHP_INT_MAX);

        self::assertSame(0, $store->read($key));
        self::assertSame(1, $store->increment($key, time() + 60));
        self::assertSame(1, $store->read($key));
        self::assertSame(2, $store->increment($key, time() + 60));
    }

    public function testRedisStoreCountsAtomicallyAcrossConnections(): void
    {
        if (!extension_loaded('redis')) self::markTestSkipped('A extensão redis não está carregada.');

        $config = self::rateLimitConfig(['store' => 'redis', 'redis' => ['host' => (string) (env('REDIS_HOST') ?: ''), 'port' => (int) (env('REDIS_PORT') ?: 6379), 'password' => (string) (env('REDIS_PASSWORD') ?: '')]]);
        if ($config->redisHost === '') self::markTestSkipped('REDIS_HOST não está configurado.');

        try {
            $first = RedisRateLimitStore::fromConfig($config);
            $second = RedisRateLimitStore::fromConfig($config);
        } catch (\RuntimeException $e) {
            self::markTestSkipped('Redis indisponível: ' . $e->getMessage());
        }

        $key = 'test:' . bin2hex(random_bytes(8));
        $expiresAt = time() + 30;

        // Conexões distintas veem o mesmo contador: é isso que o store de cache
        // não garante, porque lê e grava em dois passos.
        self::assertSame(1, $first->increment($key, $expiresAt));
        self::assertSame(2, $second->increment($key, $expiresAt));
        self::assertSame(2, $first->read($key));
        self::assertSame(0, $first->read($key . ':missing'));
    }

    public function testConfigurationRejectsAnUnknownStoreAndAnIncompletePolicy(): void
    {
        $this->expectException(ConfigurationException::class);
        self::rateLimitConfig(['store' => 'memcached']);
    }

    public function testConfigurationRejectsAPolicyWithoutAWindow(): void
    {
        $this->expectException(ConfigurationException::class);
        self::rateLimitConfig(['policies' => ['login' => ['limit' => 5]]]);
    }

    public function testConfigurationRequiresAHostForTheRedisStore(): void
    {
        $this->expectException(ConfigurationException::class);
        self::rateLimitConfig(['store' => 'redis']);
    }

    public function testFactoryBuildsTheDeclaredStrategyWithoutTouchingTheBackend(): void
    {
        // O store é construído sob demanda: um Redis inalcançável não pode
        // derrubar o bootstrap antes da estratégia de falha ser aplicada.
        $limiter = RateLimiterFactory::fromConfig(
            self::rateLimitConfig(['store' => 'redis', 'strategy' => 'sliding_window', 'on_failure' => 'closed', 'redis' => ['host' => '127.0.0.1', 'port' => 1]]),
        );

        self::assertInstanceOf(ResilientRateLimiter::class, $limiter);
        self::assertFalse($limiter->attempt('client', 5, 60)->allowed);
    }

    /** Um atributo que aponta para uma política inexistente é erro de compilação, não 500 em produção. */
    public function testRouteCacheRefusesAnAttributeThatNamesAnUndeclaredPolicy(): void
    {
        $map = RouteCompiler::compile((new AttributeLoader())->load([RateLimitControllerFixture::class]));
        $unknown = (new \ReflectionMethod(RouteCacheCommand::class, 'unknownPolicies'))->invoke(null, $map);

        self::assertSame(['nao-declarada'], $unknown);
    }

    /** @param array<string,mixed> $overrides */
    private static function rateLimitConfig(array $overrides): RateLimitConfig
    {
        $defaults = ['store' => 'cache', 'strategy' => 'fixed_window', 'on_failure' => 'open', 'redis' => [], 'policies' => []];
        $rateLimit = [...$defaults, ...$overrides];
        $rateLimit['redis'] = [...['host' => '', 'port' => 6379, 'password' => '', 'prefix' => 'neoframework:rl:test:', 'timeout' => 0.5], ...$rateLimit['redis']];

        return RateLimitConfig::from(new ConfigRepository(sys_get_temp_dir(), ['rate_limit' => $rateLimit], false));
    }
}
