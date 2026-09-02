<?php
declare(strict_types=1);

namespace Tests;

use DI\ContainerBuilder;
use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Application;
use NeoFramework\Core\Attributes\Route;
use NeoFramework\Core\Http\RequestScopeContext;
use NeoFramework\Core\Http\ResettableInterface;
use NeoFramework\Core\HttpKernel;
use NeoFramework\Core\Request;
use NeoFramework\Core\Response;
use NeoFramework\Core\Routing\AttributeLoader;
use NeoFramework\Core\Routing\Matcher;
use NeoFramework\Core\Routing\RouteCompiler;
use NeoFramework\Core\Runtime\FrankenPhpWorker;
use NeoFramework\Core\Runtime\RuntimeReporterInterface;
use PHPUnit\Framework\TestCase;

final class RuntimeControllerFixture extends Controller
{
    #[Route('/runtime/{value}', ['GET'])]
    public function show(string $value): Response { return $this->json(['value' => $value]); }

    #[Route('/runtime/fail', ['GET'])]
    public function fail(): Response { throw new \RuntimeException('worker failure'); }
}

final class RuntimeResetProbe implements ResettableInterface
{
    public int $resets = 0;
    public function reset(): void { $this->resets++; }
}

final class FailingRuntimeResetProbe implements ResettableInterface
{
    public function reset(): void { throw new \RuntimeException('cannot reset'); }
}

final class RuntimeReporterProbe implements RuntimeReporterInterface
{
    /** @var list<string> */ public array $services = [];
    public function resetFailed(string $service, \Throwable $error): void { $this->services[] = $service . ':' . $error->getMessage(); }
}

final class NeoFrameworkRuntimeTest extends TestCase
{
    private function application(ResettableInterface ...$resettables): Application
    {
        $routes = RouteCompiler::compile((new AttributeLoader())->load([RuntimeControllerFixture::class]));
        $kernel = new HttpKernel((new ContainerBuilder())->build(), new Matcher($routes));

        return new Application($kernel, $resettables);
    }

    public function testWorkerCanSafelyServeOneThousandRequestsInOneProcess(): void
    {
        $probe = new RuntimeResetProbe();
        $application = $this->application($probe);

        // O primeiro lote permite que autoload/reflection e o allocator façam
        // o warmup. O requisito do worker é estabilizar depois dele.
        for ($i = 0; $i < 100; $i++) {
            $application->handle(new Request('GET', '/runtime/warmup-' . $i));
        }
        gc_collect_cycles();
        $before = memory_get_usage(true);

        for ($i = 0; $i < 1000; $i++) {
            $response = $application->handle(new Request('GET', '/runtime/' . $i, ['Accept' => 'application/json']));
            self::assertSame(200, $response->getStatusCode());
            self::assertNull(RequestScopeContext::current());
        }

        gc_collect_cycles();
        self::assertSame(1100, $probe->resets);
        self::assertLessThanOrEqual(1024 * 1024, memory_get_usage(true) - $before, 'A memória cresceu além do warmup esperado.');
    }

    public function testAnExceptionDoesNotPoisonTheWorkerOrSkipCleanup(): void
    {
        $probe = new RuntimeResetProbe();
        $application = $this->application($probe);

        try {
            $application->handle(new Request('GET', '/runtime/fail'));
            self::fail('A exceção deveria chegar ao adapter do runtime.');
        } catch (\RuntimeException $error) {
            self::assertSame('worker failure', $error->getMessage());
        }

        self::assertSame(1, $probe->resets);
        self::assertSame(200, $application->handle(new Request('GET', '/runtime/healthy'))->getStatusCode());
        self::assertSame(2, $probe->resets);
    }

    public function testAResetFailureIsReportedWithoutBreakingTheNextRequest(): void
    {
        $reporter = new RuntimeReporterProbe();
        $routes = RouteCompiler::compile((new AttributeLoader())->load([RuntimeControllerFixture::class]));
        $kernel = new HttpKernel((new ContainerBuilder())->build(), new Matcher($routes));
        $application = new Application($kernel, ['queue' => new FailingRuntimeResetProbe()], $reporter);

        self::assertSame(200, $application->handle(new Request('GET', '/runtime/one'))->getStatusCode());
        self::assertSame(['queue:cannot reset'], $reporter->services);
    }

    public function testFrankenPhpAdapterFailsClearlyOutsideTheWorkerRuntime(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('FrankenPHP worker API is unavailable');

        (new FrankenPhpWorker($this->application()))->run(1);
    }
}
