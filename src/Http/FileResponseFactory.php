<?php
declare(strict_types=1);

namespace NeoFramework\Core\Http;

use GuzzleHttp\Psr7\LimitStream;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Utils;
use NeoFramework\Core\Exceptions\NotFoundException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Respostas de arquivo: download, inline, `Range` e revalidação.
 *
 * Fica fora de `Response` de propósito. Range, ETag e `Content-Disposition`
 * carregam regras suficientes para justificar um lugar próprio — e inflar a
 * resposta de uso geral com elas faria todo controller pagar por algo que
 * poucos usam.
 */
final class FileResponseFactory
{
    /** Anexo: o browser baixa em vez de renderizar. */
    public static function download(string $path, ?string $filename = null, string $mediaType = 'application/octet-stream', ?ServerRequestInterface $request = null): ResponseInterface
    {
        return self::file($path, $mediaType, self::disposition('attachment', $filename ?? basename($path)), $request);
    }

    /** Inline: o browser exibe se souber. Só para tipo que você controla. */
    public static function inline(string $path, ?string $filename = null, string $mediaType = 'application/octet-stream', ?ServerRequestInterface $request = null): ResponseInterface
    {
        return self::file($path, $mediaType, self::disposition('inline', $filename ?? basename($path)), $request);
    }

    /** Download de conteúdo já em memória, sem passar por arquivo. */
    public static function fromString(string $contents, string $filename, string $mediaType = 'application/octet-stream'): ResponseInterface
    {
        return (new PsrResponse(200, [
            'Content-Type' => $mediaType,
            'Content-Length' => (string) strlen($contents),
            'Content-Disposition' => self::disposition('attachment', $filename),
            'X-Content-Type-Options' => 'nosniff',
        ], Utils::streamFor($contents)));
    }

    private static function file(string $path, string $mediaType, string $disposition, ?ServerRequestInterface $request): ResponseInterface
    {
        if (!is_file($path) || !is_readable($path)) throw new NotFoundException();

        $size = filesize($path);
        $modified = filemtime($path);
        if ($size === false || $modified === false) throw new NotFoundException();

        $etag = self::etag($path, $size, $modified);
        $headers = [
            'Content-Type' => $mediaType,
            'Content-Disposition' => $disposition,
            // Sem isto o browser pode ignorar o Content-Type declarado e adivinhar
            // pelo conteúdo — que é como um upload vira HTML executável.
            'X-Content-Type-Options' => 'nosniff',
            'Accept-Ranges' => 'bytes',
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s T', $modified),
        ];

        if ($request !== null && ConditionalRequest::isFresh($request, $etag, $modified)) {
            return new PsrResponse(304, $headers);
        }

        $range = $request === null || !self::rangeApplies($request, $etag, $modified)
            ? null
            : ByteRange::parse($request->getHeaderLine('Range'), $size);

        if ($range === false) {
            return new PsrResponse(416, [...$headers, 'Content-Range' => "bytes */{$size}"]);
        }

        if ($range === null) {
            return new PsrResponse(200, [...$headers, 'Content-Length' => (string) $size], self::stream($path));
        }

        return new PsrResponse(206, [
            ...$headers,
            'Content-Length' => (string) $range->length(),
            'Content-Range' => $range->contentRange($size),
        ], new LimitStream(self::stream($path), $range->length(), $range->start));
    }

    /**
     * `If-Range` protege contra retomar um download de um arquivo que mudou:
     * quando o validador não bate, o certo é mandar o arquivo inteiro.
     */
    private static function rangeApplies(ServerRequestInterface $request, string $etag, int $modified): bool
    {
        $ifRange = $request->getHeaderLine('If-Range');
        if ($ifRange === '') return true;

        return str_contains($ifRange, $etag) || strtotime($ifRange) === $modified;
    }

    private static function stream(string $path): StreamInterface
    {
        return Utils::streamFor(Utils::tryFopen($path, 'rb'));
    }

    /** Validador forte derivado de identidade do arquivo, sem ler o conteúdo. */
    private static function etag(string $path, int $size, int $modified): string
    {
        return '"' . hash('xxh128', $path . ':' . $size . ':' . $modified) . '"';
    }

    private static function disposition(string $type, string $filename): string
    {
        // Uma sequência de bytes não-ASCII é UM caractere: substituir byte a byte
        // transformaria "relatório" em "relat__rio".
        $fallback = preg_replace('/[^\x20-\x7e]+/', '_', basename($filename)) ?? 'download';
        $fallback = str_replace(['"', '\\'], '_', $fallback);

        // O nome pode ter acento e o header é ASCII: RFC 6266 pede as duas formas.
        return sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $type, $fallback, rawurlencode(basename($filename)));
    }
}
