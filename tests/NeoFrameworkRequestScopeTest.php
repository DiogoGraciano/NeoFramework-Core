<?php
declare(strict_types=1);

namespace Tests;

use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Attributes\Route;
use NeoFramework\Core\Http\RequestAttributes;
use NeoFramework\Core\Http\RequestScopeContext;
use NeoFramework\Core\Http\RequestScopeInterface;
use NeoFramework\Core\Middleware\ErrorHandler;
use NeoFramework\Core\Response;
use NeoFramework\Core\Session;
use NeoFramework\Core\Testing\TestClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ScopeControllerFixture extends Controller
{
    #[Route('/scope/{value}', ['GET'])]
    public function show(string $value, ServerRequestInterface $request): Response
    {
        $before = Session::get('value');
        Session::set('value', $value);
        $scope = $request->getAttribute(RequestAttributes::SCOPE);
        return $this->json(['before' => $before, 'value' => Session::get('value'), 'route' => $scope instanceof RequestScopeInterface ? $scope->get(RequestAttributes::ROUTE_VARIABLES) : null]);
    }
}

final class ScopeProbeMiddleware implements MiddlewareInterface
{
    public ?RequestScopeInterface $scope = null;
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $scope = $request->getAttribute(RequestAttributes::SCOPE);
        if ($scope instanceof RequestScopeInterface) $this->scope = $scope;
        return $handler->handle($request);
    }
}

final class ScopeThrowingMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        throw new \RuntimeException('scope cleanup');
    }
}

final class NeoFrameworkRequestScopeTest extends TestCase
{
    public function testHundredsOfRequestsDoNotLeakSessionOrRouteData(): void
    {
        $client = TestClient::forControllers([ScopeControllerFixture::class]);
        for ($i = 0; $i < 200; $i++) {
            $value = 'request-' . $i;
            $client->getJson('/scope/' . $value)->assertOk()->assertJsonPath('before', null)->assertJsonPath('value', $value)->assertJsonPath('route.value', $value);
        }
        self::assertNull(RequestScopeContext::current());
    }

    public function testScopeIsClearedAfterTheResponse(): void
    {
        $probe = new ScopeProbeMiddleware();
        TestClient::forControllers([ScopeControllerFixture::class], [$probe])->getJson('/scope/one')->assertOk();
        self::assertInstanceOf(RequestScopeInterface::class, $probe->scope);
        self::assertFalse($probe->scope->has(RequestAttributes::ROUTE));
        self::assertNull(RequestScopeContext::current());
    }

    public function testScopeIsClearedWhenMiddlewareThrows(): void
    {
        TestClient::forControllers([ScopeControllerFixture::class], [ErrorHandler::class, new ScopeThrowingMiddleware()])->getJson('/scope/one')->assertStatus(500);
        self::assertNull(RequestScopeContext::current());
    }
}
