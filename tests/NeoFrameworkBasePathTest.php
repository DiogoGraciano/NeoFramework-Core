<?php
declare(strict_types=1);

namespace Tests;

use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Attributes\Route;
use NeoFramework\Core\Config;
use NeoFramework\Core\Config\ConfigRepository;
use NeoFramework\Core\Http\BasePath;
use NeoFramework\Core\Request;
use NeoFramework\Core\Response;
use NeoFramework\Core\Routing\AttributeLoader;
use NeoFramework\Core\Routing\RouteCompiler;
use NeoFramework\Core\Routing\UrlGenerator;
use NeoFramework\Core\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class BasePathControllerFixture extends Controller
{
    #[Route('/users/{id:\\d+}', ['GET'], false, 'basepath.users.show')]
    public function show(int $id): Response { return $this->json(['id' => $id]); }
}

final class BasePathOptionalFixture extends Controller
{
    #[Route('/posts/{slug}/{page?}', ['GET'], false, 'basepath.posts')]
    public function posts(string $slug, ?string $page = null): Response { return $this->text($slug); }
}

final class NeoFrameworkBasePathTest extends TestCase
{
    private array $compiled;

    protected function setUp(): void
    {
        $this->compiled = RouteCompiler::compile((new AttributeLoader())->load([BasePathControllerFixture::class]));
        $this->useBasePath(null);
    }

    protected function tearDown(): void
    {
        Config::reset();
    }

    private function useBasePath(?string $path): void
    {
        Config::setRepository(new ConfigRepository(sys_get_temp_dir(), ['http' => ['base_path' => $path]], false));
    }

    public function testResolvePrefersTheExplicitConfiguredValue(): void
    {
        $this->useBasePath('/app');
        self::assertSame('/app', BasePath::resolve(['SCRIPT_NAME' => '/outro/index.php']));

        $this->useBasePath('app/');
        self::assertSame('/app', BasePath::resolve([]), 'normaliza barras');

        $this->useBasePath('/');
        self::assertSame('', BasePath::resolve(['SCRIPT_NAME' => '/app/index.php']), 'raiz explícita vence a derivação');
    }

    public function testResolveFallsBackToScriptName(): void
    {
        self::assertSame('/app', BasePath::resolve(['SCRIPT_NAME' => '/app/index.php']));
        self::assertSame('', BasePath::resolve(['SCRIPT_NAME' => '/index.php']));
        self::assertSame('', BasePath::resolve([]));
    }

    public function testStripRespectsSegmentBoundaries(): void
    {
        self::assertSame('/users/1', BasePath::strip('/app/users/1', '/app'));
        self::assertSame('/', BasePath::strip('/app', '/app'));
        self::assertSame('/users/1', BasePath::strip('/users/1', ''));
        // "/application" não pode ser tratado como estando sob "/app"
        self::assertSame('/application/users', BasePath::strip('/application/users', '/app'));
    }

    public function testKernelRoutesUnderASubdirectoryInstall(): void
    {
        TestClient::forControllers([BasePathControllerFixture::class])
            ->withBasePath('/app')
            ->getJson('/users/42')
            ->assertOk()
            ->assertJsonPath('id', 42);
    }

    public function testWithoutTheBasePathTheSubdirectoryRequestIsNotFound(): void
    {
        TestClient::forControllers([BasePathControllerFixture::class])->getJson('/app/users/42')->assertNotFound();
    }

    public function testGeneratedUrlsCarryTheBasePath(): void
    {
        self::assertSame('/app/users/7', (new UrlGenerator($this->compiled['named'], '/app'))->path('basepath.users.show', ['id' => 7]));
        self::assertSame('/users/7', (new UrlGenerator($this->compiled['named']))->path('basepath.users.show', ['id' => 7]));
        self::assertSame(
            '/app/users/7?tab=x',
            (new UrlGenerator($this->compiled['named'], '/app'))->path('basepath.users.show', ['id' => 7], ['tab' => 'x'])
        );
    }

    public function testOmittedOptionalDoesNotLeaveATrailingSlash(): void
    {
        $compiled = RouteCompiler::compile((new AttributeLoader())->load([BasePathOptionalFixture::class]));
        $generator = new UrlGenerator($compiled['named']);

        self::assertSame('/posts/ola', $generator->path('basepath.posts', ['slug' => 'ola']));
        self::assertSame('/posts/ola/3', $generator->path('basepath.posts', ['slug' => 'ola', 'page' => 3]));
        self::assertSame('/app/posts/ola', (new UrlGenerator($compiled['named'], '/app'))->path('basepath.posts', ['slug' => 'ola']));
    }

    public function testFromGlobalsAttachesTheBasePath(): void
    {
        $this->useBasePath('/app');
        $server = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/app/users/42';
        $_SERVER['HTTP_HOST'] = 'example.com';

        try {
            $request = Request::fromGlobals();
            self::assertSame('/app', $request->basePath());
        } finally {
            $_SERVER = $server;
        }
    }
}
