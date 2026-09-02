<?php

declare(strict_types=1);

namespace Tests;

use GuzzleHttp\Psr7\ServerRequest;
use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Attributes\Route;
use NeoFramework\Core\Events\EventDispatcher;
use NeoFramework\Core\Events\ExceptionRaised;
use NeoFramework\Core\Events\ListenerProvider;
use NeoFramework\Core\Events\ResponseCreated;
use NeoFramework\Core\Exceptions\NotFoundException;
use NeoFramework\Core\Http\RequestAttributes;
use NeoFramework\Core\Observability\HttpMetricsListener;
use NeoFramework\Core\Observability\InMemoryMetricsExporter;
use NeoFramework\Core\Observability\NullMetricsExporter;
use NeoFramework\Core\Observability\NullTracer;
use NeoFramework\Core\Response;
use NeoFramework\Core\Routing\RouteDefinition;
use NeoFramework\Core\Testing\TestClient;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ServerRequestInterface;

final class MetricsControllerFixture extends Controller
{
    #[Route('/orders/{id:\d+}', ['GET'], name: 'orders.show')]
    public function show(int $id): Response
    {
        return $this->json(['id' => $id]);
    }

    #[Route('/boom', ['GET'], name: 'boom')]
    public function boom(): Response
    {
        throw new NotFoundException();
    }
}

final class NeoFrameworkMetricsTest extends TestCase
{
    private InMemoryMetricsExporter $metrics;

    protected function setUp(): void
    {
        $this->metrics = new InMemoryMetricsExporter();
    }

    private function request(?RouteDefinition $route): ServerRequestInterface
    {
        $request = new ServerRequest('GET', '/orders/8123');

        return $route === null ? $request : $request->withAttribute(RequestAttributes::ROUTE, $route);
    }

    public function testAResponseBecomesACounterAndAHistogram(): void
    {
        $route = new RouteDefinition('/orders/{id}', ['GET'], MetricsControllerFixture::class, 'show', 'orders.show');
        (new HttpMetricsListener($this->metrics))(new ResponseCreated($this->request($route), new Response(200), 12.5));

        $counter = $this->metrics->samples(HttpMetricsListener::REQUESTS);
        self::assertCount(1, $counter);
        self::assertSame(1.0, $counter[0]['value']);
        self::assertSame(['method' => 'GET', 'route' => 'orders.show', 'status' => '200'], $counter[0]['labels']);

        $histogram = $this->metrics->samples(HttpMetricsListener::DURATION);
        self::assertSame('histogram', $histogram[0]['type']);
        self::assertSame(12.5, $histogram[0]['value']);
    }

    /**
     * O label precisa ser o padrão da rota. Usar o path resolvido cria uma série
     * temporal por pedido e derruba o backend com a própria instrumentação.
     */
    public function testTheRouteLabelIsThePatternAndNotTheResolvedPath(): void
    {
        $route = new RouteDefinition('/orders/{id}', ['GET'], MetricsControllerFixture::class, 'show');
        (new HttpMetricsListener($this->metrics))(new ResponseCreated($this->request($route), new Response(200), 1.0));

        $labels = $this->metrics->samples(HttpMetricsListener::REQUESTS)[0]['labels'];
        self::assertSame('/orders/{id}', $labels['route']);
        self::assertStringNotContainsString('8123', implode(' ', $labels));
    }

    public function testAnUnmatchedRequestGetsAClosedLabelInsteadOfThePath(): void
    {
        (new HttpMetricsListener($this->metrics))(new ResponseCreated($this->request(null), new Response(404), 1.0));

        self::assertSame('<unmatched>', $this->metrics->samples(HttpMetricsListener::REQUESTS)[0]['labels']['route']);
    }

    /** A mensagem da exceção costuma carregar dado do cliente; a classe não. */
    public function testExceptionsAreCountedByClassAndNotByMessage(): void
    {
        $route = new RouteDefinition('/boom', ['GET'], MetricsControllerFixture::class, 'boom', 'boom');
        $exception = new NotFoundException('Pedido 8123 do usuário joao@example.com não existe');
        (new HttpMetricsListener($this->metrics))(new ExceptionRaised($this->request($route), $exception, 404));

        $labels = $this->metrics->samples(HttpMetricsListener::EXCEPTIONS)[0]['labels'];
        self::assertSame(NotFoundException::class, $labels['type']);
        self::assertStringNotContainsString('joao@example.com', implode(' ', $labels));
        self::assertStringNotContainsString('8123', implode(' ', $labels));
    }

    /** Um evento que o listener não conhece não pode virar métrica nem erro. */
    public function testAnUnknownEventIsIgnored(): void
    {
        (new HttpMetricsListener($this->metrics))(new \stdClass());

        self::assertSame([], $this->metrics->samples());
    }

    public function testTheDefaultAdaptersDoNothingAndDoNotThrow(): void
    {
        $metrics = new NullMetricsExporter();
        $metrics->counter('x');
        $metrics->gauge('y', 1.0);
        $metrics->histogram('z', 2.0);

        $span = (new NullTracer())->startSpan('nada', ['a' => 'b']);
        $span->setAttribute('k', 1)->recordException(new \RuntimeException('boom'))->end();

        self::assertTrue(true, 'Os adapters no-op não lançam nem exigem configuração.');
    }

    /** Fim a fim: o listener registrado pelo evento mede a requisição real. */
    public function testMetricsAreCollectedThroughTheRealRequestCycle(): void
    {
        $provider = new ListenerProvider();
        $listener = new HttpMetricsListener($this->metrics);
        $provider->on(ResponseCreated::class, $listener);
        $provider->on(ExceptionRaised::class, $listener);

        $container = (new \DI\ContainerBuilder())->build();
        $container->set(EventDispatcherInterface::class, new EventDispatcher($provider));

        TestClient::forControllers([MetricsControllerFixture::class], container: $container)
            ->getJson('/orders/8123')->assertOk();

        $counter = $this->metrics->samples(HttpMetricsListener::REQUESTS);
        self::assertCount(1, $counter);
        self::assertSame('orders.show', $counter[0]['labels']['route']);
        self::assertSame('200', $counter[0]['labels']['status']);
        self::assertGreaterThan(0.0, $this->metrics->samples(HttpMetricsListener::DURATION)[0]['value']);
    }
}
