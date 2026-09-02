<?php
declare(strict_types=1);

namespace NeoFramework\Core\Http;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Psr\Http\Message\ResponseInterface;

/** Respostas cujo corpo é produzido enquanto é enviado. */
final class StreamedResponseFactory
{
    private function __construct() {}

    /** @param callable():\Generator<int,string> $chunks */
    public static function stream(callable $chunks, string $mediaType = 'text/plain; charset=utf-8', int $status = 200): ResponseInterface
    {
        return new PsrResponse($status, ['Content-Type' => $mediaType, 'X-Content-Type-Options' => 'nosniff'], new CallbackStream($chunks));
    }

    /**
     * Array JSON emitido item a item.
     *
     * @param iterable<mixed> $items
     */
    public static function json(iterable $items, int $status = 200): ResponseInterface
    {
        return self::stream(static function () use ($items): \Generator {
            yield '[';
            $first = true;
            foreach ($items as $item) {
                yield ($first ? '' : ',') . json_encode($item, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $first = false;
            }
            yield ']';
        }, 'application/json; charset=utf-8', $status);
    }

    /**
     * Server-Sent Events.
     *
     * `X-Accel-Buffering: no` desliga o buffer do nginx: sem ele o proxy segura
     * os eventos e entrega tudo junto no fim, que é o oposto do ponto de SSE.
     *
     * @param callable():\Generator<int,ServerSentEvent> $events
     */
    public static function eventStream(callable $events): ResponseInterface
    {
        return new PsrResponse(200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => CacheControl::noStore()->toHeader(),
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ], new CallbackStream(static function () use ($events): \Generator {
            foreach ($events() as $event) yield $event->toWire();
        }));
    }
}
