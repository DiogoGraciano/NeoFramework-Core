<?php
declare(strict_types=1);

namespace Tests;

use DI\ContainerBuilder;
use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Application;
use NeoFramework\Core\Attributes\Route;
use NeoFramework\Core\HttpKernel;
use NeoFramework\Core\Request;
use NeoFramework\Core\Response;
use NeoFramework\Core\Routing\AttributeLoader;
use NeoFramework\Core\Routing\Matcher;
use NeoFramework\Core\Routing\RouteCompiler;
use NeoFramework\RoadRunner\RoadRunnerHttpWorkerInterface;
use NeoFramework\RoadRunner\RoadRunnerWorker;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RoadRunnerControllerFixture extends Controller
{
    #[Route('/rr/ok', ['GET'])]
    public function ok(): Response { return $this->json(['ok' => true]); }

    #[Route('/rr/fail', ['GET'])]
    public function fail(): Response { throw new \RuntimeException('request exploded'); }
}

final class RoadRunnerWorkerProbe implements RoadRunnerHttpWorkerInterface
{
    /** @param list<ServerRequestInterface|null> $requests */
    public function __construct(private array $requests) {}

    /** @var list<ResponseInterface> */ public array $responses = [];
    /** @var list<string> */ public array $errors = [];
    public int $stops = 0;

    public function waitRequest(): ?ServerRequestInterface { return array_shift($this->requests); }
    public function respond(ResponseInterface $response): void { $this->responses[] = $response; }
    public function report(\Throwable $error): void { $this->errors[] = $error->getMessage(); }
    public function stop(): void { $this->stops++; }
}

final class NeoFrameworkRoadRunnerAdapterTest extends TestCase
{
    private function application(): Application
    {
        $routes = RouteCompiler::compile((new AttributeLoader())->load([RoadRunnerControllerFixture::class]));
        return new Application(new HttpKernel((new ContainerBuilder())->build(), new Matcher($routes)));
    }

    public function testItReportsOneRequestFailureAndContinuesWithTheWorker(): void
    {
        $probe = new RoadRunnerWorkerProbe([
            new Request('GET', '/rr/fail'),
            new Request('GET', '/rr/ok'),
        ]);

        (new RoadRunnerWorker())->run($this->application(), $probe, maxRequests: 2);

        self::assertSame(['request exploded'], $probe->errors);
        self::assertCount(1, $probe->responses);
        self::assertSame(200, $probe->responses[0]->getStatusCode());
        self::assertSame(1, $probe->stops);
    }

    public function testItDoesNotStopAWorkerWhenTheServerEndsTheStream(): void
    {
        $probe = new RoadRunnerWorkerProbe([new Request('GET', '/rr/ok'), null]);

        (new RoadRunnerWorker())->run($this->application(), $probe);

        self::assertCount(1, $probe->responses);
        self::assertSame(0, $probe->stops);
    }
}
