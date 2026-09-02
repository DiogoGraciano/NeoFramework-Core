<?php
declare(strict_types=1);

namespace NeoFramework\Core\Http;

use InvalidArgumentException;

/** Um evento SSE. */
final readonly class ServerSentEvent
{
    public function __construct(
        public string $data,
        public ?string $event = null,
        public ?string $id = null,
        public ?int $retry = null,
    ) {
        // Uma quebra de linha no id ou no nome do evento injetaria campos no
        // fluxo — o cliente leria um evento que ninguém emitiu.
        foreach ([$event, $id] as $field) {
            if ($field !== null && preg_match('/[\r\n]/', $field) === 1) throw new InvalidArgumentException('Campos de SSE não podem conter quebras de linha.');
        }
    }

    public function toWire(): string
    {
        $lines = [];

        if ($this->id !== null) $lines[] = 'id: ' . $this->id;
        if ($this->event !== null) $lines[] = 'event: ' . $this->event;
        if ($this->retry !== null) $lines[] = 'retry: ' . $this->retry;
        // Um dado multilinha vira vários campos `data:`; o cliente os rejunta.
        foreach (preg_split('/\r\n|\r|\n/', $this->data) ?: [''] as $line) $lines[] = 'data: ' . $line;

        return implode("\n", $lines) . "\n\n";
    }
}
