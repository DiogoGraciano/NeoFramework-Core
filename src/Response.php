<?php
declare(strict_types=1);

namespace NeoFramework\Core;

use GuzzleHttp\Psr7\Response as GuzzleResponse;
use NeoFramework\Core\Abstract\Layout;
use NeoFramework\Core\Http\CacheControl;
use NeoFramework\Core\Http\Cookie;

/** PSR-7 response with NeoFramework convenience helpers. */
final class Response extends GuzzleResponse
{
    public function getCode(): int { return $this->getStatusCode(); }
    public function getContent(): string { return (string) $this->getBody(); }

    public function withContentType(string $type, ?string $charset = null): static
    {
        return $this->withHeader('Content-Type', $charset ? "$type; charset=$charset" : $type);
    }
    public function withExpiration(?string $time): static
    {
        return $this->withHeader('Expires', $time === null ? '0' : gmdate('D, d M Y H:i:s', strtotime($time)) . ' GMT');
    }
    public function addContent(object|string|array $content): static
    {
        if ($content instanceof Layout) $content = $content->parse();
        elseif (is_array($content) || is_object($content)) $content = json_encode($content, JSON_THROW_ON_ERROR);
        $this->getBody()->write((string) $content);
        return $this;
    }
    public function json(mixed $data, int $status = 200): static
    {
        $response = $this->withStatus($status)->withHeader('Content-Type', 'application/json; charset=UTF-8');
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $response;
    }
    public function html(string|Layout $content, int $status = 200): static
    {
        return $this->withStatus($status)->withHeader('Content-Type', 'text/html; charset=UTF-8')->addContent($content);
    }
    public function text(string $content, int $status = 200): static
    {
        return $this->withStatus($status)->withHeader('Content-Type', 'text/plain; charset=UTF-8')->addContent($content);
    }
    public function download(string $contents, string $filename, string $mediaType = 'application/octet-stream', int $status = 200): static
    {
        $filename = str_replace(["\r", "\n", '"'], '', basename($filename));
        if ($filename === '') throw new \InvalidArgumentException('A download filename is required.');

        return $this->withStatus($status)
            ->withHeader('Content-Type', $mediaType)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename))
            ->withHeader('Content-Length', (string) strlen($contents))
            ->addContent($contents);
    }
    public function go(string $path, int $status = 302): static
    {
        $normalized = str_replace('\\', '/', $path);
        if (str_contains($normalized, '://') || str_starts_with($normalized, '//')) {
            throw new \InvalidArgumentException('go() aceita apenas caminhos internos; use goToSite() para URLs absolutas.');
        }
        return $this->withStatus($status)->withHeader('Location', Url::getUrlBase() . ltrim($normalized, '/'));
    }
    public function goToSite(string $url, int $status = 302): static
    {
        if (!in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new \InvalidArgumentException('goToSite() aceita apenas URLs http ou https.');
        }
        return $this->withStatus($status)->withHeader('Location', $url);
    }

    public function withCookie(string $name, string $value, string|int|\DateTimeInterface|null $expires = null, string $path = '/', ?string $domain = null, bool $secure = false, bool $httpOnly = true, string $sameSite = 'Lax'): static
    {
        return $this->withCookieObject((new Cookie($name, $value, null, $path, $domain, $secure, $httpOnly, $sameSite))->withExpiration($expires));
    }

    /** Os atributos de segurança do cookie são validados na construção do valor. */
    public function withCookieObject(Cookie $cookie): static
    {
        return $this->withAddedHeader('Set-Cookie', $cookie->toHeader());
    }

    public function withoutCookie(string $name, string $path = '/', ?string $domain = null, bool $secure = false): static
    {
        return $this->withCookieObject(Cookie::expiring($name, $path, $domain, $secure));
    }

    public function withCacheControl(CacheControl $cacheControl): static
    {
        return $this->withHeader('Cache-Control', $cacheControl->toHeader());
    }

    public function withEtag(string $etag, bool $weak = false): static
    {
        $etag = '"' . trim($etag, '"') . '"';

        return $this->withHeader('ETag', $weak ? 'W/' . $etag : $etag);
    }

    public function withLastModified(\DateTimeInterface|int $modified): static
    {
        return $this->withHeader('Last-Modified', gmdate('D, d M Y H:i:s T', $modified instanceof \DateTimeInterface ? $modified->getTimestamp() : $modified));
    }
}
