<?php
declare(strict_types=1);

namespace Tests;

use DI\ContainerBuilder;
use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Attributes\Route;
use NeoFramework\Core\Attributes\RoutePrefix;
use NeoFramework\Core\HttpKernel;
use NeoFramework\Core\Middleware\ErrorHandler;
use NeoFramework\Core\Request;
use NeoFramework\Core\Response;
use NeoFramework\Core\Routing\AttributeLoader;
use NeoFramework\Core\Routing\Matcher;
use NeoFramework\Core\Routing\RouteCompiler;
use NeoFramework\Core\Routing\UrlGenerator;
use PHPUnit\Framework\TestCase;

#[RoutePrefix('/api/users')]
final class RoutingControllerFixture extends Controller
{
    #[Route('/{id:\\d+}', ['GET'], false, 'users.show')]
    public function show(int $id): Response { return $this->json(['id' => $id]); }

    #[Route('/', ['POST'], false, 'users.store')]
    public function store(): Response { return (new Response())->withStatus(201); }

    #[Route('/codes/{code:[A-Z]{2,3}}', ['GET'], false)]
    public function code(string $code): Response { return $this->text($code); }
}

final class NeoFrameworkRouterTest extends TestCase
{
    private array $compiled;
    protected function setUp(): void
    {
        $this->compiled = RouteCompiler::compile((new AttributeLoader())->load([RoutingControllerFixture::class]));
    }

    public function testStaticDynamicHeadAndMethodNotAllowedMatching(): void
    {
        $matcher = new Matcher($this->compiled);
        $found = $matcher->match('GET', '/api/users/42');
        self::assertSame('found', $found->status);
        self::assertSame(['id' => '42'], $found->variables);
        self::assertSame('found', $matcher->match('HEAD', '/api/users/42')->status);
        $wrongMethod = $matcher->match('DELETE', '/api/users/42');
        self::assertSame('method_not_allowed', $wrongMethod->status);
        self::assertContains('GET', $wrongMethod->allowedMethods);
        self::assertContains('HEAD', $wrongMethod->allowedMethods);
        self::assertSame('not_found', $matcher->match('GET', '/missing')->status);
        self::assertSame('found', $matcher->match('GET', '/api/users/codes/ABC')->status);
        self::assertSame('not_found', $matcher->match('GET', '/api/users/codes/ABCD')->status);
    }

    public function testNamedRouteGeneration(): void
    {
        $generator = new UrlGenerator($this->compiled['named']);
        self::assertSame('/api/users/7?tab=posts', $generator->path('users.show', ['id' => 7], ['tab' => 'posts']));
    }

    public function testKernelDispatchesAndReturns405WithAllowHeader(): void
    {
        $container = (new ContainerBuilder())->build();
        $kernel = new HttpKernel($container, new Matcher($this->compiled), [ErrorHandler::class]);
        $response = $kernel->handle(new Request('GET', '/api/users/9', ['Accept' => 'application/json']));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"id":9}', (string) $response->getBody());
        $response = $kernel->handle(new Request('DELETE', '/api/users/9'));
        self::assertSame(405, $response->getStatusCode());
        self::assertStringContainsString('GET', $response->getHeaderLine('Allow'));

        $head = $kernel->handle(new Request('HEAD', '/api/users/9'));
        self::assertSame(200, $head->getStatusCode());
        self::assertSame('', (string) $head->getBody());
    }
}
