<?php
declare(strict_types=1);

namespace NeoFramework\Core;

use GuzzleHttp\Psr7\CachingStream;
use GuzzleHttp\Psr7\LazyOpenStream;
use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Utils;
use NeoFramework\Core\Http\BasePath;
use NeoFramework\Core\Http\RequestAttributes;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use SimpleXMLElement;

/** PSR-7 server request with NeoFramework convenience accessors. */
final class Request extends ServerRequest
{
    public function __construct(string $method = 'GET', mixed $uri = '/', array $headers = [], mixed $body = null, string $version = '1.1', array $serverParams = [])
    {
        parent::__construct($method, $uri, $headers, $body, $version, $serverParams);
    }

    public static function fromGlobals(): static
    {
        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $body = new CachingStream(new LazyOpenStream('php://input', 'r+'));
        $protocol = str_replace('HTTP/', '', (string) ($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1'));
        $request = new static($method, self::uriFromGlobals($_SERVER), self::getAllHeaders(), $body, $protocol, $_SERVER);

        return $request
            ->withCookieParams($_COOKIE)
            ->withQueryParams($_GET)
            ->withParsedBody($_POST)
            ->withUploadedFiles(ServerRequest::normalizeFiles($_FILES))
            ->withAttribute(RequestAttributes::BASE_PATH, BasePath::resolve($_SERVER));
    }

    public static function isXmlHttpRequest(?ServerRequestInterface $request = null): bool
    {
        $value = $request?->getHeaderLine('X-Requested-With') ?? (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
        return strtolower($value) === 'xmlhttprequest';
    }

    public static function getAllHeaders(): array
    {
        if (function_exists('getallheaders')) {
            return getallheaders() ?: [];
        }
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_')) {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        foreach (['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'] as $name) {
            if (isset($_SERVER[$name])) {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $name))))] = $_SERVER[$name];
            }
        }
        return $headers;
    }

    /** Prefixo sob o qual a aplicação está montada ('' na raiz). */
    public function basePath(): string { return (string) $this->getAttribute(RequestAttributes::BASE_PATH, ''); }

    public function headerLine(string $name): ?string
    {
        $value = $this->getHeaderLine($name);
        return $value === '' ? null : $value;
    }

    public function get(string $name, bool $sanitized = false): mixed { return $this->value($this->getQueryParams(), $name, $sanitized); }
    public function post(string $name, bool $sanitized = false): mixed
    {
        $body = $this->getParsedBody();
        return $this->value(is_array($body) ? $body : [], $name, $sanitized);
    }
    public function cookie(string $name, bool $sanitized = false): mixed { return $this->value($this->getCookieParams(), $name, $sanitized); }
    public function server(string $name): mixed { return $this->getServerParams()[$name] ?? null; }

    public function getCsrfToken(): ?string
    {
        $token = $this->post('CSRF_TOKEN') ?? $this->get('CSRF_TOKEN') ?? $this->headerLine('X-CSRF-TOKEN');
        return is_string($token) ? $token : null;
    }

    public function getArray(bool $sanitized = false): array { return $sanitized ? $this->sanitize($this->getQueryParams()) : $this->getQueryParams(); }
    public function postArray(bool $sanitized = false): array
    {
        $body = $this->getParsedBody();
        $body = is_array($body) ? $body : [];
        return $sanitized ? $this->sanitize($body) : $body;
    }
    public function cookieArray(bool $sanitized = false): array { return $sanitized ? $this->sanitize($this->getCookieParams()) : $this->getCookieParams(); }
    public function serverArray(): array { return $this->getServerParams(); }
    public function filesArray(): array { return $this->getUploadedFiles(); }
    public function file(string $name): UploadedFileInterface|array|null { return $this->getUploadedFiles()[$name] ?? null; }
    public function bodyString(): string { return (string) $this->getBody(); }
    public function withJsonBody(mixed $data): static
    {
        return $this->withHeader('Content-Type', 'application/json')->withBody(Utils::streamFor(json_encode($data, JSON_THROW_ON_ERROR)));
    }
    public function withXmlBody(SimpleXMLElement $xml): static
    {
        return $this->withHeader('Content-Type', 'application/xml')->withBody(Utils::streamFor($xml->asXML() ?: ''));
    }
    public function getBodyAsJson(bool $asArray = false): mixed { return json_decode($this->bodyString(), $asArray); }
    public function getBodyAsXml(): SimpleXMLElement|false { return simplexml_load_string($this->bodyString()); }
    public function contentType(): string { return $this->getHeaderLine('Content-Type'); }

    public function all(): array
    {
        $all = array_merge($this->getArray(), $this->postArray());
        if (str_starts_with(strtolower($this->contentType()), 'application/json')) {
            $json = $this->getBodyAsJson(true);
            if (is_array($json)) $all = array_merge($all, $json);
        }
        return array_merge($all, $this->filesArray());
    }

    private static function uriFromGlobals(array $server): Uri
    {
        $scheme = Url::isSecure() ? 'https' : 'http';
        $host = (string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? 'localhost');
        $target = (string) ($server['REQUEST_URI'] ?? '/');
        return new Uri($scheme . '://' . $host . ($target === '' ? '/' : $target));
    }
    private function value(array $source, string $name, bool $sanitized): mixed
    {
        $value = $source[$name] ?? null;
        return $sanitized ? $this->sanitize($value) : $value;
    }
    private function sanitize(mixed $value): mixed
    {
        if (is_array($value)) return array_map(fn (mixed $item): mixed => $this->sanitize($item), $value);
        return is_string($value) ? htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $value;
    }
}
