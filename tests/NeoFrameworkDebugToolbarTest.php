<?php
declare(strict_types=1);

namespace Tests;

use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Attributes\Route;
use NeoFramework\Core\Debug\DebugCollector;
use NeoFramework\Core\Debug\Profile;
use NeoFramework\Core\Debug\ProfileStore;
use NeoFramework\Core\Events\CacheAccessed;
use NeoFramework\Core\Events\ControllerInvoked;
use NeoFramework\Core\Events\QueryExecuted;
use NeoFramework\Core\Events\ResponseCreated;
use NeoFramework\Core\Middleware\DebugToolbarMiddleware;
use NeoFramework\Core\Request;
use NeoFramework\Core\Response;
use NeoFramework\Core\Routing\RouteDefinition;
use NeoFramework\Core\Support\ProjectRoot;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ToolbarControllerFixture extends Controller
{
    #[Route('/toolbar', ['GET'], false, 'toolbar.index')]
    public function index(): Response { return $this->json(['ok' => true]); }
}

/** Handler mínimo: a toolbar tem de funcionar sem roteamento. */
final class StubHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return (new Response())->json(['ok' => true]);
    }
}

final class NeoFrameworkDebugToolbarTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'neof-debug-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0775, true);
        ProjectRoot::set($this->root);
    }

    protected function tearDown(): void
    {
        (new ProfileStore())->clear();
        @rmdir(ProfileStore::directory());
        @rmdir($this->root . '/Cache');
        @rmdir($this->root);
        ProjectRoot::set(null);
    }

    public function testDisabledTheMiddlewareDoesNotTouchTheResponse(): void
    {
        $response = (new DebugToolbarMiddleware(false))->process(new Request('GET', '/qualquer'), new StubHandler());

        // Em produção o profiler tem de ser inerte — nem token, nem endpoint.
        self::assertSame('', $response->getHeaderLine('X-Debug-Token'));
    }

    public function testDisabledTheEndpointIsNotServed(): void
    {
        $response = (new DebugToolbarMiddleware(false))->process(new Request('GET', '/_debug'), new StubHandler());

        // Cai no handler da aplicação: para ela, /_debug é uma rota como outra
        // qualquer, e o profiler não existe.
        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('profiles', (string) $response->getBody());
    }

    public function testEachResponseCarriesItsOwnToken(): void
    {
        $middleware = new DebugToolbarMiddleware(true);

        $first = $middleware->process(new Request('GET', '/a'), new StubHandler())->getHeaderLine('X-Debug-Token');
        $second = $middleware->process(new Request('GET', '/b'), new StubHandler())->getHeaderLine('X-Debug-Token');

        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $first);
        self::assertNotSame($first, $second);
    }

    public function testTheCollectorAggregatesTheRequestAndTheEndpointServesIt(): void
    {
        $store = new ProfileStore();
        $collector = new DebugCollector($store);
        $route = new RouteDefinition('/toolbar', ['GET'], ToolbarControllerFixture::class, 'index', 'toolbar.index');

        $collector(new ControllerInvoked(new Request('GET', '/toolbar'), $route, 4.0));
        $collector(new CacheAccessed(true));
        $collector(new CacheAccessed(false));
        $collector(new QueryExecuted('SELECT 1', 1.5));
        $collector(new QueryExecuted('SELECT 2', 2.5));
        $collector(new QueryExecuted('INSERT INTO t VALUES (1)', 1.0));

        $response = (new Response())->json(['ok' => true])->withHeader('X-Debug-Token', 'abcdef0123456789');
        $collector(new ResponseCreated(new Request('GET', '/toolbar'), $response, 10.0));

        $served = (new DebugToolbarMiddleware(true, $store))->process(new Request('GET', '/_debug/abcdef0123456789'), new StubHandler());
        self::assertSame(200, $served->getStatusCode());

        $profile = json_decode((string) $served->getBody(), true);
        self::assertSame(['hit' => 1, 'miss' => 1], $profile['cache']);
        // Consultas agrupadas por operação, com a soma do tempo.
        self::assertSame(2, $profile['queries']['select']['count']);
        // JSON não distingue 4.0 de 4; o que importa é o valor, não o tipo.
        self::assertSame(4.0, (float) $profile['queries']['select']['durationMs']);
        self::assertSame(1, $profile['queries']['insert']['count']);
        self::assertSame(4.0, (float) $profile['controllerMs']);
        self::assertSame(10.0, (float) $profile['durationMs']);
    }

    public function testAResponseWithoutATokenIsNotStored(): void
    {
        $store = new ProfileStore();
        $collector = new DebugCollector($store);

        $collector(new ResponseCreated(new Request('GET', '/x'), (new Response())->json([]), 1.0));

        // Sem token não há como consultar o perfil depois; gravá-lo só encheria
        // o disco com registros inalcançáveis.
        self::assertSame([], $store->recent());
    }

    public function testATraversalTokenCannotReadFilesOutsideTheStore(): void
    {
        $store = new ProfileStore();

        // O token vem da URL; sem validar, "../" transformaria o profiler num
        // leitor de arquivos arbitrários.
        self::assertNull($store->find('../../../etc/passwd'));
        self::assertNull($store->find('nao-hexadecimal'));
    }

    public function testTheStoreKeepsOnlyTheMostRecentProfiles(): void
    {
        $store = new ProfileStore(limit: 3);

        foreach (range(1, 6) as $i) {
            $store->save(new Profile(str_pad((string) $i, 16, '0', STR_PAD_LEFT), 'GET', "/p{$i}", null, 200, 1.0, 0.5, 1024, collectedAt: (float) $i));
        }

        // Sem teto o diretório cresceria sem fim numa máquina de desenvolvimento.
        self::assertCount(3, $store->recent(100));
    }

    public function testRecentReturnsTheNewestFirst(): void
    {
        $store = new ProfileStore();
        $store->save(new Profile('1000000000000000', 'GET', '/velho', null, 200, 1.0, 0.5, 1024, collectedAt: 100.0));
        $store->save(new Profile('2000000000000000', 'GET', '/novo', null, 200, 1.0, 0.5, 1024, collectedAt: 200.0));

        self::assertSame('/novo', $store->recent()[0]->path);
    }

    public function testAnUnknownTokenIs404(): void
    {
        $response = (new DebugToolbarMiddleware(true))->process(new Request('GET', '/_debug/00000000deadbeef'), new StubHandler());

        self::assertSame(404, $response->getStatusCode());
    }
}
