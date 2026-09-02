<?php

declare(strict_types=1);

namespace NeoFramework\Core\Observability;

/** Não traça nada. É o padrão. */
final class NullTracer implements TracerInterface
{
    public function startSpan(string $name, array $attributes = []): SpanInterface
    {
        return new NullSpan();
    }
}
