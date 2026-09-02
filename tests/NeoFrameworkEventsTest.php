<?php
declare(strict_types=1);

namespace Tests;

use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Attributes\Route;
use NeoFramework\Core\Events\EventDispatcher;
use NeoFramework\Core\Events\Events;
use NeoFramework\Core\Events\ExceptionRaised;
use NeoFramework\Core\Events\ListenerProvider;
use NeoFramework\Core\Events\RequestReceived;
use NeoFramework\Core\Events\ResponseCreated;
use NeoFramework\Core\Events\RouteMatched;
use NeoFramework\Core\Response;
use NeoFramework\Core\Testing\TestClient;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

final class EventsControllerFixture extends Controller
{
    #[Route('/ping', ['GET'], false, 'events.ping')]
    public function ping(): Response { return $this->json(['ok' => true]); }

    #[Route('/boom', ['GET'], false, 'events.boom')]
    public function boom(): Response { throw new \RuntimeException('falhou'); }
}

/** Coletor de eventos, registrado por class-string como em produção. */
final class RecordingListener
{
    /** @var list<object> */
    public static array $seen = [];
    public function __invoke(object $event): void { self::$seen[] = $event; }
}

/** Segundo coletor, para provar ordem e memo. */
final class SecondRecordingListener
{
    /** @var list<object> */
    public static array $seen = [];
    public function __invoke(object $event): void { self::$seen[] = $event; }
}

final class NeoFrameworkEventsTest extends TestCase
{
    protected function setUp(): void { RecordingListener::$seen = []; }

    private function clientWithListeners(array $events): TestClient
    {
        $provider = new ListenerProvider();
        foreach ($events as $event) $provider->on($event, RecordingListener::class);

        $container = (new \DI\ContainerBuilder())->build();
        $container->set(EventDispatcherInterface::class, new EventDispatcher($provider));

        return TestClient::forControllers([EventsControllerFixture::class], container: $container);
    }

    public function testLifecycleEventsAreDispatchedInOrder(): void
    {
        $this->clientWithListeners([RequestReceived::class, RouteMatched::class, ResponseCreated::class])
            ->get('/ping')->assertOk();

        $types = array_map(static fn (object $e): string => (new \ReflectionClass($e))->getShortName(), RecordingListener::$seen);
        self::assertSame(['RequestReceived', 'RouteMatched', 'ResponseCreated'], $types);
    }

    public function testRouteMatchedCarriesTheResolvedAction(): void
    {
        $this->clientWithListeners([RouteMatched::class])->get('/ping')->assertOk();

        $event = RecordingListener::$seen[0];
        self::assertInstanceOf(RouteMatched::class, $event);
        self::assertSame('events.ping', $event->name());
        self::assertStringContainsString('EventsControllerFixture::ping', $event->action());
    }

    public function testResponseCreatedCarriesStatusAndDuration(): void
    {
        $this->clientWithListeners([ResponseCreated::class])->get('/ping')->assertOk();

        $event = RecordingListener::$seen[0];
        self::assertInstanceOf(ResponseCreated::class, $event);
        self::assertSame(200, $event->status());
        self::assertGreaterThanOrEqual(0.0, $event->durationMs);
    }

    public function testExceptionRaisedCarriesTheStatusThatWillBeAnswered(): void
    {
        $this->clientWithListeners([ExceptionRaised::class])->get('/boom')->assertServerError();

        $event = RecordingListener::$seen[0];
        self::assertInstanceOf(ExceptionRaised::class, $event);
        self::assertSame(500, $event->status);
        self::assertSame('falhou', $event->exception->getMessage());
    }

    /** Instrumentação desligada não pode alterar o comportamento. */
    public function testWithoutListenersTheApplicationBehavesIdentically(): void
    {
        TestClient::forControllers([EventsControllerFixture::class])->get('/ping')->assertOk()->assertJsonPath('ok', true);
        self::assertSame([], RecordingListener::$seen);
    }

    /** Sem escopo ativo, despachar é no-op e não estoura. */
    public function testDispatchOutsideARequestIsANoOp(): void
    {
        $event = new \stdClass();
        self::assertSame($event, Events::dispatch($event));
        self::assertNull(Events::dispatcher());
    }

    public function testListenerOrderFollowsPriorityThenRegistration(): void
    {
        $order = [];
        $provider = (new ListenerProvider())
            ->on(RequestReceived::class, function () use (&$order) { $order[] = 'baixa'; }, -10)
            ->on(RequestReceived::class, function () use (&$order) { $order[] = 'alta'; }, 10)
            ->on(RequestReceived::class, function () use (&$order) { $order[] = 'media'; });

        (new EventDispatcher($provider))->dispatch(new RequestReceived(new \NeoFramework\Core\Request('GET', '/'), 'abc'));

        self::assertSame(['alta', 'media', 'baixa'], $order);
    }

    public function testInvalidListenerIsRejectedAtRegistration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ListenerProvider())->on(RequestReceived::class, 'Classe\\Que\\Nao\\Existe');
    }

    public function testUnknownEventIsRejectedAtRegistration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ListenerProvider())->on('Evento\\Inexistente', RecordingListener::class);
    }

    public function testControllerInvocationIsObservableAroundTheActionOnly(): void
    {
        $client = $this->clientWithListeners([
            \NeoFramework\Core\Events\ControllerInvoking::class,
            \NeoFramework\Core\Events\ControllerInvoked::class,
        ]);

        $client->getJson('/ping')->assertOk();

        $classes = array_map(static fn (object $e): string => $e::class, RecordingListener::$seen);
        self::assertSame([
            \NeoFramework\Core\Events\ControllerInvoking::class,
            \NeoFramework\Core\Events\ControllerInvoked::class,
        ], $classes);

        $invoked = RecordingListener::$seen[1];
        self::assertInstanceOf(\NeoFramework\Core\Events\ControllerInvoked::class, $invoked);
        self::assertSame('ping', $invoked->route->action);
        self::assertGreaterThanOrEqual(0.0, $invoked->durationMs);
    }

    public function testAnActionThatThrowsDoesNotAnnounceItselfAsInvoked(): void
    {
        $client = $this->clientWithListeners([
            \NeoFramework\Core\Events\ControllerInvoking::class,
            \NeoFramework\Core\Events\ControllerInvoked::class,
        ]);

        $client->getJson('/boom')->assertStatus(500);

        // Anunciar "invoked" com a duração de um trabalho que não terminou
        // poluiria qualquer média de latência.
        $classes = array_map(static fn (object $e): string => $e::class, RecordingListener::$seen);
        self::assertSame([\NeoFramework\Core\Events\ControllerInvoking::class], $classes);
    }

    public function testRegisteredMapIsInspectable(): void
    {
        $map = (new ListenerProvider())->on(RequestReceived::class, RecordingListener::class)->registered();

        self::assertSame([RequestReceived::class => [RecordingListener::class]], $map);
    }

    public function testCompiledMapPreservesExecutionOrder(): void
    {
        $provider = (new ListenerProvider())
            ->on(RequestReceived::class, RecordingListener::class)
            ->on(RequestReceived::class, SecondRecordingListener::class, 10);

        // Prioridade maior primeiro: a ordem tem de sobreviver à compilação,
        // ou o mapa compilado executa numa sequência diferente da declarada.
        $compiled = $provider->compilable();
        self::assertSame(
            [SecondRecordingListener::class, RecordingListener::class],
            array_column($compiled[RequestReceived::class], 'listener'),
        );

        $rebuilt = \NeoFramework\Core\Events\ListenerProvider::fromCompiled($compiled);
        self::assertSame($provider->registered(), $rebuilt->registered());
    }

    public function testARoundTripThroughTheCompiledMapDispatchesTheSameListeners(): void
    {
        $compiled = (new ListenerProvider())->on(RequestReceived::class, RecordingListener::class)->compilable();
        $dispatcher = new \NeoFramework\Core\Events\EventDispatcher(
            \NeoFramework\Core\Events\ListenerProvider::fromCompiled($compiled),
        );

        $dispatcher->dispatch(new RequestReceived(new \NeoFramework\Core\Request(), 'req-1'));

        self::assertCount(1, RecordingListener::$seen);
    }

    public function testAClosureListenerIsRefusedByTheCompilerInsteadOfBeingDropped(): void
    {
        $provider = (new ListenerProvider())->on(RequestReceived::class, static fn (object $e): null => null);

        // Descartar em silêncio produziria um mapa compilado que roda menos que
        // o configurado — e um listener ausente é indistinguível de um que não fez nada.
        $this->expectException(\LogicException::class);
        $provider->compilable();
    }

    /** O memo de correspondência não pode alterar o que é despachado. */
    public function testMemoisingTheMatchedListenersKeepsOrderAndSeesLateRegistrations(): void
    {
        $provider = (new ListenerProvider())->on(RequestReceived::class, RecordingListener::class, 10);
        $event = new RequestReceived(new \NeoFramework\Core\Request(), 'req-1');

        $classes = static fn (iterable $listeners): array => array_map(
            static fn (object $listener): string => $listener::class,
            iterator_to_array($listeners),
        );

        self::assertSame([RecordingListener::class], $classes($provider->getListenersForEvent($event)));
        self::assertSame([RecordingListener::class], $classes($provider->getListenersForEvent($event)));

        // Registrar depois de despachar invalida o memo. Sem isso a lista velha
        // esconderia o listener novo, e com prioridade maior ainda por cima.
        $provider->on(RequestReceived::class, SecondRecordingListener::class, 20);
        self::assertSame(
            [SecondRecordingListener::class, RecordingListener::class],
            $classes($provider->getListenersForEvent($event)),
        );
    }
}
