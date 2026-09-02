<?php

declare(strict_types=1);

namespace NeoFramework\OpenTelemetry;

use NeoFramework\Core\Observability\SpanInterface;
use OpenTelemetry\API\Trace\SpanInterface as OtelSpan;
use OpenTelemetry\API\Trace\StatusCode;
use Throwable;

/** Um span do OTel por trás do contrato do Core. */
final readonly class OpenTelemetrySpan implements SpanInterface
{
    public function __construct(private OtelSpan $span)
    {
    }

    public function setAttribute(string $key, string|int|float|bool $value): static
    {
        $this->span->setAttribute($key, $value);

        return $this;
    }

    public function recordException(Throwable $exception): static
    {
        $this->span->recordException($exception);
        // Registrar a exceção não marca o span como falho no OTel — sem o
        // status, um trace com erro aparece verde no backend.
        $this->span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());

        return $this;
    }

    public function end(): void
    {
        $this->span->end();
    }
}
