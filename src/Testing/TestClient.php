<?php

declare(strict_types=1);

namespace NeoFramework\Core\Testing;

use DI\ContainerBuilder;
use GuzzleHttp\Psr7\UploadedFile;
use GuzzleHttp\Psr7\Utils;
use NeoFramework\Core\Auth\IdentityInterface;
use NeoFramework\Core\Http\RequestAttributes;
use NeoFramework\Core\HttpKernel;
use NeoFramework\Core\Middleware\ErrorHandler;
use NeoFramework\Core\Request;
use NeoFramework\Core\Routing\AttributeLoader;
use NeoFramework\Core\Routing\Matcher;
use NeoFramework\Core\Routing\RouteCompiler;
use NeoFramework\Core\Session;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Dispara requisições contra o HttpKernel sem servidor, sem superglobais e sem
 * output buffering.
 *
 * É a colheita de `HttpKernel::handle()` ser puro: a mesma pilha de produção,
 * exercitada em memória.
 */
final class TestClient
{
    private CookieJar $cookies;

    /** @var array<string,string> */
    private array $headers = [];

    /** @var array<string,mixed> */
    private array $serverParams = [];

    private string $basePath = '';

    private ?IdentityInterface $identity = null;
    private string $guard = 'session';

    public function __construct(private readonly HttpKernel $kernel)
    {
        $this->cookies = new CookieJar();
    }

    /**
     * Monta um kernel a partir de classes de controller, sem varrer o disco e
     * sem tocar no cache de rotas.
     *
     * @param list<class-string> $controllers
     * @param list<class-string|object> $middleware
     */
    public static function forControllers(
        array $controllers,
        array $middleware = [ErrorHandler::class],
        ?ContainerInterface $container = null,
    ): self {
        $compiled = RouteCompiler::compile((new AttributeLoader())->load($controllers));

        return new self(new HttpKernel(
            $container ?? (new ContainerBuilder())->build(),
            new Matcher($compiled),
            $middleware,
        ));
    }

    // ----- configuração do cliente -----------------------------------------

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /** @param array<string,string> $headers */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = $value;
        }

        return $this;
    }

    public function withCookie(string $name, string $value): self
    {
        $this->cookies->set($name, $value);

        return $this;
    }

    public function withBasePath(string $basePath): self
    {
        $this->basePath = $basePath;

        return $this;
    }

    /** @param array<string,mixed> $params */
    public function withServerParams(array $params): self
    {
        $this->serverParams = [...$this->serverParams, ...$params];

        return $this;
    }

    /**
     * Semeia a sessão com um token de CSRF e passa a enviá-lo, para que testes
     * de POST não precisem mexer em $_SESSION na mão.
     */
    public function withCsrfToken(): self
    {
        return $this->withHeader('X-CSRF-TOKEN', Session::regenerateCsrfToken());
    }

    /** Autentica somente as requisições deste cliente, sem sessão global. */
    public function actingAs(IdentityInterface $identity, string $guard = 'session'): self
    {
        $this->identity = $identity;
        $this->guard = $guard;

        return $this;
    }

    public function cookies(): CookieJar
    {
        return $this->cookies;
    }

    // ----- verbos -----------------------------------------------------------

    /** @param array<string,string> $headers */
    public function get(string $uri, array $headers = []): TestResponse { return $this->request('GET', $uri, headers: $headers); }
    public function head(string $uri, array $headers = []): TestResponse { return $this->request('HEAD', $uri, headers: $headers); }
    public function options(string $uri, array $headers = []): TestResponse { return $this->request('OPTIONS', $uri, headers: $headers); }
    public function delete(string $uri, array $headers = []): TestResponse { return $this->request('DELETE', $uri, headers: $headers); }

    /** @param array<string,mixed> $body */
    public function post(string $uri, array $body = [], array $headers = []): TestResponse { return $this->request('POST', $uri, $body, $headers); }
    public function put(string $uri, array $body = [], array $headers = []): TestResponse { return $this->request('PUT', $uri, $body, $headers); }
    public function patch(string $uri, array $body = [], array $headers = []): TestResponse { return $this->request('PATCH', $uri, $body, $headers); }

    /** @param array<string,mixed> $fields @param array<string,UploadedFileInterface> $files */
    public function postMultipart(string $uri, array $fields, array $files, array $headers = []): TestResponse
    {
        return $this->request('POST', $uri, $fields, ['Content-Type' => 'multipart/form-data', ...$headers], uploadedFiles: $files);
    }

    public static function uploadedFile(string $contents, string $filename, string $mediaType = 'application/octet-stream'): UploadedFileInterface
    {
        return new UploadedFile(Utils::streamFor($contents), strlen($contents), UPLOAD_ERR_OK, $filename, $mediaType);
    }

    /** @param array<string,string> $headers */
    public function getJson(string $uri, array $headers = []): TestResponse { return $this->json('GET', $uri, null, $headers); }
    public function postJson(string $uri, array $data = [], array $headers = []): TestResponse { return $this->json('POST', $uri, $data, $headers); }
    public function putJson(string $uri, array $data = [], array $headers = []): TestResponse { return $this->json('PUT', $uri, $data, $headers); }
    public function patchJson(string $uri, array $data = [], array $headers = []): TestResponse { return $this->json('PATCH', $uri, $data, $headers); }
    public function deleteJson(string $uri, array $data = [], array $headers = []): TestResponse { return $this->json('DELETE', $uri, $data, $headers); }

    /** @param array<mixed>|null $data @param array<string,string> $headers */
    public function json(string $method, string $uri, ?array $data = null, array $headers = []): TestResponse
    {
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json', ...$headers];

        return $this->request($method, $uri, null, $headers, $data === null ? '' : json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string,mixed>|null $parsedBody
     * @param array<string,string> $headers
     */
    public function request(string $method, string $uri, ?array $parsedBody = null, array $headers = [], ?string $rawBody = null, array $uploadedFiles = []): TestResponse
    {
        $request = new Request(
            strtoupper($method),
            $this->basePath !== '' && !str_starts_with($uri, $this->basePath) ? $this->basePath . $uri : $uri,
            [...$this->headers, ...$headers],
            $rawBody === null ? null : Utils::streamFor($rawBody),
            '1.1',
            $this->serverParams,
        );

        $query = [];
        parse_str($request->getUri()->getQuery(), $query);

        $request = $request
            ->withQueryParams($query)
            ->withCookieParams($this->cookies->all())
            ->withUploadedFiles($uploadedFiles)
            ->withAttribute(RequestAttributes::BASE_PATH, $this->basePath);

        if ($this->identity !== null) {
            $request = $request
                ->withAttribute(RequestAttributes::AUTH_IDENTITY, $this->identity)
                ->withAttribute(RequestAttributes::AUTH_GUARD, $this->guard);
        }

        if ($parsedBody !== null) {
            $request = $request
                ->withParsedBody($parsedBody)
                ->withHeader('Content-Type', $uploadedFiles === [] ? 'application/x-www-form-urlencoded' : 'multipart/form-data')
                ->withBody(Utils::streamFor(http_build_query($parsedBody)));
        }

        $response = $this->kernel->handle($request);
        $this->cookies->absorb($response->getHeader('Set-Cookie'));

        return new TestResponse($response);
    }
}
