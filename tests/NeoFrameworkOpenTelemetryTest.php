<?php
declare(strict_types=1);

namespace Tests;

use NeoFramework\OpenTelemetry\OpenTelemetryMetricsExporter;
use NeoFramework\OpenTelemetry\OpenTelemetryTracer;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\GaugeInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface as OtelSpan;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface as OtelTracer;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

// O adapter testa só a ponte para OTel; mocks de tracer e builder existem para
// devolver o próximo objeto da cadeia, não para afirmar cada chamada auxiliar.
#[AllowMockObjectsWithoutExpectations]
final class NeoFrameworkOpenTelemetryTest extends TestCase
{
    /** @return array{0:OtelTracer,1:OtelSpan&\PHPUnit\Framework\MockObject\MockObject,2:SpanBuilderInterface&\PHPUnit\Framework\MockObject\MockObject} */
    private function tracer(): array
    {
        // Mock e não dublê escrito à mão: a `SpanInterface` do OTel declara
        // métodos estáticos, que uma implementação manual teria de reproduzir
        // sem que nenhum deles participe do que está sendo testado.
        $span = $this->createMock(OtelSpan::class);
        $builder = $this->createMock(SpanBuilderInterface::class);
        $builder->method('startSpan')->willReturn($span);
        $builder->method('setAttribute')->willReturnSelf();

        $tracer = $this->createMock(OtelTracer::class);
        $tracer->method('spanBuilder')->willReturn($builder);

        return [$tracer, $span, $builder];
    }

    public function testTheSpanIsBuiltWithItsNameAndAttributes(): void
    {
        [$tracer, , $builder] = $this->tracer();
        $tracer->expects(self::once())->method('spanBuilder')->with('http.request')->willReturn($builder);

        $attributes = [];
        $builder->method('setAttribute')->willReturnCallback(function (string $k, mixed $v) use (&$attributes, $builder): SpanBuilderInterface {
            $attributes[$k] = $v;

            return $builder;
        });

        (new OpenTelemetryTracer($tracer))->startSpan('http.request', ['route' => 'users.index', 'status' => 200]);

        self::assertSame(['route' => 'users.index', 'status' => 200], $attributes);
    }

    public function testRecordingAnExceptionAlsoMarksTheSpanAsFailed(): void
    {
        [$tracer, $span] = $this->tracer();
        $exception = new \RuntimeException('quebrou');

        $span->expects(self::once())->method('recordException')->with($exception)->willReturnSelf();
        // Registrar a exceção não marca o span como falho no OTel: sem o status,
        // um trace com erro aparece verde no backend.
        $span->expects(self::once())->method('setStatus')->with(StatusCode::STATUS_ERROR, 'quebrou')->willReturnSelf();

        (new OpenTelemetryTracer($tracer))->startSpan('job')->recordException($exception);
    }

    public function testAttributesSetAfterStartReachTheSpan(): void
    {
        [$tracer, $span] = $this->tracer();
        $span->expects(self::once())->method('setAttribute')->with('rows', 42)->willReturnSelf();

        (new OpenTelemetryTracer($tracer))->startSpan('query')->setAttribute('rows', 42);
    }

    public function testEndingTheSpanEndsTheUnderlyingOne(): void
    {
        [$tracer, $span] = $this->tracer();
        $span->expects(self::once())->method('end');

        (new OpenTelemetryTracer($tracer))->startSpan('job')->end();
    }

    public function testInstrumentsAreCreatedOncePerMetricName(): void
    {
        $meter = new CountingMeter();
        $exporter = new OpenTelemetryMetricsExporter($meter);

        $exporter->counter('http.requests', 1, ['route' => 'a']);
        $exporter->counter('http.requests', 1, ['route' => 'b']);
        $exporter->histogram('http.duration_ms', 5.0);
        $exporter->histogram('http.duration_ms', 7.0);

        // Recriar o instrumento a cada chamada produz série duplicada no backend
        // além de alocar em todo request.
        self::assertSame(1, $meter->created['counter:http.requests'] ?? null);
        self::assertSame(1, $meter->created['histogram:http.duration_ms'] ?? null);
    }

    public function testEachMetricTypeReachesItsOwnInstrument(): void
    {
        $meter = new CountingMeter();
        $exporter = new OpenTelemetryMetricsExporter($meter);

        $exporter->counter('m', 3, ['a' => 'b']);
        $exporter->gauge('m', 1.5);
        $exporter->histogram('m', 2.5);

        // Mesmo nome, tipos diferentes: sem separar por tipo na chave, o gauge
        // reusaria o contador e um valor instantâneo viraria soma.
        self::assertSame(1, $meter->created['counter:m'] ?? null);
        self::assertSame(1, $meter->created['gauge:m'] ?? null);
        self::assertSame(1, $meter->created['histogram:m'] ?? null);
        self::assertSame([[3, ['a' => 'b']]], $meter->counterCalls);
    }
}

final class CountingCounter implements CounterInterface
{
    public function __construct(private readonly CountingMeter $meter) {}
    public function add($amount, iterable $attributes = [], $context = null): void { $this->meter->counterCalls[] = [$amount, $attributes]; }
    public function isEnabled(): bool { return true; }
}

final class CountingGauge implements GaugeInterface
{
    public function record($amount, iterable $attributes = [], $context = null): void {}
    public function isEnabled(): bool { return true; }
}

final class CountingHistogram implements HistogramInterface
{
    public function record($amount, iterable $attributes = [], $context = null): void {}
    public function isEnabled(): bool { return true; }
}

/** Conta quantos instrumentos o adapter pediu, por tipo e nome. */
final class CountingMeter implements MeterInterface
{
    /** @var array<string,int> */
    public array $created = [];
    /** @var list<array{0:mixed,1:iterable}> */
    public array $counterCalls = [];

    public function createCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): CounterInterface
    {
        $this->created['counter:' . $name] = ($this->created['counter:' . $name] ?? 0) + 1;

        return new CountingCounter($this);
    }

    public function createGauge(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): GaugeInterface
    {
        $this->created['gauge:' . $name] = ($this->created['gauge:' . $name] ?? 0) + 1;

        return new CountingGauge();
    }

    public function createHistogram(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): HistogramInterface
    {
        $this->created['histogram:' . $name] = ($this->created['histogram:' . $name] ?? 0) + 1;

        return new CountingHistogram();
    }

    public function createObservableCounter(string $name, ?string $unit = null, ?string $description = null, $advisory = [], callable ...$callbacks): \OpenTelemetry\API\Metrics\ObservableCounterInterface { throw new \LogicException('não usado'); }
    public function createUpDownCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): \OpenTelemetry\API\Metrics\UpDownCounterInterface { throw new \LogicException('não usado'); }
    public function createObservableUpDownCounter(string $name, ?string $unit = null, ?string $description = null, $advisory = [], callable ...$callbacks): \OpenTelemetry\API\Metrics\ObservableUpDownCounterInterface { throw new \LogicException('não usado'); }
    public function createObservableGauge(string $name, ?string $unit = null, ?string $description = null, $advisory = [], callable ...$callbacks): \OpenTelemetry\API\Metrics\ObservableGaugeInterface { throw new \LogicException('não usado'); }
    public function batchObserve(callable $callback, \OpenTelemetry\API\Metrics\AsynchronousInstrument $instrument, \OpenTelemetry\API\Metrics\AsynchronousInstrument ...$instruments): \OpenTelemetry\API\Metrics\ObservableCallbackInterface { throw new \LogicException('não usado'); }
}
