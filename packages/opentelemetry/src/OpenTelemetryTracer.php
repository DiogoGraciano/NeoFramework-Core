<?php

declare(strict_types=1);

namespace NeoFramework\OpenTelemetry;

use NeoFramework\Core\Observability\SpanInterface;
use NeoFramework\Core\Observability\TracerInterface;
use OpenTelemetry\API\Trace\TracerInterface as OtelTracer;

/**
 * Adapter do `TracerInterface` do Core para o OpenTelemetry.
 *
 * Vive fora do Core porque o SDK do OTel traz uma árvore de dependências
 * própria, e uma aplicação que não exporta trace nenhum não deve carregá-la.
 * O contrato do Core continua sendo o mínimo — quem sabe de propagação,
 * sampling e export é este pacote.
 */
final readonly class OpenTelemetryTracer implements TracerInterface
{
    public function __construct(private OtelTracer $tracer)
    {
    }

    public function startSpan(string $name, array $attributes = []): SpanInterface
    {
        $builder = $this->tracer->spanBuilder($name);
        foreach ($attributes as $key => $value) $builder->setAttribute($key, $value);

        // `startSpan` e não `activate`: ativar o span mexe no contexto global do
        // OTel, e quem decide se este span deve virar o pai dos próximos é a
        // aplicação, não o adapter.
        return new OpenTelemetrySpan($builder->startSpan());
    }
}
