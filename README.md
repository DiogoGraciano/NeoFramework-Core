# NeoFramework Core

HTTP framework for PHP 8.4 with PSR-7 messages, PSR-11 dependency injection, PSR-15 middleware, PSR-17 factories and PSR-3 logging.

## Routing

Routes are absolute and may be named. Controller prefixes and class/method middleware are supported.

```php
#[RoutePrefix('/api/users')]
#[Middleware(ApiAuthentication::class)]
final class UserController extends Controller
{
    #[Route('/{id:\\d+}', ['GET'], name: 'users.show')]
    public function show(int $id): Response
    {
        return $this->json(['id' => $id]);
    }
}
```

`route('users.show', ['id' => 42])` returns `/api/users/42`. Optional parameters use `{slug?}`. A path that exists for another method returns `405 Method Not Allowed` with an `Allow` header; unknown paths return 404.

Compile for production with `php neof config:cache`, `route:cache`, `event:cache` and
`dto:cache`; each has a matching `:clear`. Sem eles a aplicação relê e revalida
configuração, rotas, listeners e metadata de DTO **a cada requisição**.

## HTTP and middleware

`Request` and `Response` extend Guzzle's PSR-7 messages. Header and status operations are immutable:

```php
$response = (new Response())
    ->withStatus(201)
    ->withHeader('Location', '/api/users/42')
    ->json(['id' => 42], 201);
```

Middleware implements PSR-15:

```php
final class ApiAuthentication implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)->withHeader('X-Authenticated', '1');
    }
}
```

Global application middleware may be returned from `Config/middleware.config.php` as class names or instances.

`HttpKernel::handle(ServerRequestInterface): ResponseInterface` performs no output. Only `ResponseEmitter`, called at the front-controller boundary, sends headers and body, making kernel requests straightforward to test.

## Rate limiting

Apply a fixed-window limit to a controller or action with `#[RateLimit]`. The
response includes `RateLimit-Limit`, `RateLimit-Remaining` and
`RateLimit-Reset`; an exceeded request receives 429 with `Retry-After`.
See [`docs/RATE_LIMITING.md`](docs/RATE_LIMITING.md) for keys, container adapters
and concurrency considerations.

## Persistent runtimes

`Application` provides a worker-safe `boot()` / `handle()` lifecycle and resets
registered stateful services after each request. The optional FrankenPHP adapter
is documented in [`docs/RUNTIMES.md`](docs/RUNTIMES.md).
Use `php neof doctor --runtime` before deploying a worker.

## Code generation

Use `make:controller`, `make:middleware`, `make:request`, `make:policy`,
`make:job`, `make:command` or `make:test` with a class name. Nested classes
accept `/`, such as `make:controller Admin/Users`; generators never overwrite a
file unless given `--force` and expose `--json` for automation. Run
`stubs:publish` to copy editable defaults into `resources/stubs`.

`about --json` exposes version, environment, cache status and selected adapters
without outputting configuration values or secrets.

## Support utilities

The Core exposes focused, stateless utilities: `Support\ProjectRoot`, `Support\Path`, `Support\Id`, `Support\UserAgent`, `Support\Str`, `Support\Date`, and immutable `Support\Money` values. `Functions` no longer exists.

## Optional packages

RoadRunner, OpenTelemetry, GD image processing and Brazilian document helpers
are separate packages, so applications do not install runtime integrations they
do not use. See [`packages/README.md`](packages/README.md) for their Composer
names and requirements.

## Roadmap

The architecture specification and phased implementation path are documented in [`docs/IMPLEMENTATION_ROADMAP.md`](docs/IMPLEMENTATION_ROADMAP.md).
