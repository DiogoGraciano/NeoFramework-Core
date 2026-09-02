<?php

declare(strict_types=1);

namespace NeoFramework\Core\Observability;

use Throwable;

/** Um trecho de trabalho medido dentro de um trace. */
interface SpanInterface
{
    public function setAttribute(string $key, string|int|float|bool $value): static;

    /** Marca o span como falho e registra a exceção nele. */
    public function recordException(Throwable $exception): static;

    public function end(): void;
}
