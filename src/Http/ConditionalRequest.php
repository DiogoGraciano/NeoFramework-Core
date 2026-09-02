<?php
declare(strict_types=1);

namespace NeoFramework\Core\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Decide se o que o cliente já tem em cache continua válido.
 *
 * `If-None-Match` vence `If-Modified-Since` quando ambos vêm — a regra da
 * RFC 9110, e a que importa: o ETag é preciso, o timestamp tem resolução de um
 * segundo e erra quando o arquivo muda duas vezes no mesmo segundo.
 */
final class ConditionalRequest
{
    private function __construct() {}

    public static function isFresh(ServerRequestInterface $request, ?string $etag, ?int $lastModified): bool
    {
        $ifNoneMatch = $request->getHeaderLine('If-None-Match');
        if ($ifNoneMatch !== '') return $etag !== null && self::matches($ifNoneMatch, $etag);

        $ifModifiedSince = $request->getHeaderLine('If-Modified-Since');
        if ($ifModifiedSince === '' || $lastModified === null) return false;

        $since = strtotime($ifModifiedSince);

        return $since !== false && $lastModified <= $since;
    }

    private static function matches(string $header, string $etag): bool
    {
        if (trim($header) === '*') return true;

        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            // Numa requisição condicional a comparação é fraca: `W/"x"` e `"x"`
            // designam a mesma representação.
            if (self::normalize($candidate) === self::normalize($etag)) return true;
        }

        return false;
    }

    private static function normalize(string $etag): string
    {
        return ltrim(trim($etag), 'W/');
    }
}
