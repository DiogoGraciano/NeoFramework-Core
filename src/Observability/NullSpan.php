<?php

declare(strict_types=1);

namespace NeoFramework\Core\Observability;

use Throwable;

/** Span que não registra nada, devolvido pelo `NullTracer`. */
final class NullSpan implements SpanInterface
{
    public function setAttribute(string $key, string|int|float|bool $value): static
    {
        return $this;
    }

    public function recordException(Throwable $exception): static
    {
        return $this;
    }

    public function end(): void
    {
    }
}
