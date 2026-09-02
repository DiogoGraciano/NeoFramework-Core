<?php

declare(strict_types=1);

namespace NeoFramework\Core\Observability;

/**
 * Abre spans. A implementação padrão é no-op; OpenTelemetry entra por adapter.
 *
 * O contrato é mínimo de propósito: propagação de contexto, sampling e export
 * pertencem ao adapter, e embuti-los aqui amarraria o Core a um modelo de trace
 * específico.
 */
interface TracerInterface
{
    /** @param array<string,string|int|float|bool> $attributes */
    public function startSpan(string $name, array $attributes = []): SpanInterface;
}
