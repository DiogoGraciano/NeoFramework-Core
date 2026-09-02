<?php

declare(strict_types=1);

namespace NeoFramework\Core\Testing;

use NeoFramework\Core\Middleware\ErrorHandler;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Base para testes HTTP.
 *
 * Declare os controllers em `controllers()` e use `$this->get()`, `$this->postJson()`
 * e afins. Sem servidor, sem superglobais, sem output buffering.
 */
abstract class HttpTestCase extends TestCase
{
    private ?TestClient $client = null;

    /** @return list<class-string> Controllers visíveis para este teste. */
    abstract protected function controllers(): array;

    /** @return list<class-string|object> Middleware global da pilha sob teste. */
    protected function middleware(): array
    {
        return [ErrorHandler::class];
    }

    protected function container(): ?ContainerInterface
    {
        return null;
    }

    protected function client(): TestClient
    {
        return $this->client ??= TestClient::forControllers(
            $this->controllers(),
            $this->middleware(),
            $this->container(),
        );
    }

    /** Recomeça com um cliente limpo — cookies e headers zerados. */
    protected function freshClient(): TestClient
    {
        return $this->client = TestClient::forControllers(
            $this->controllers(),
            $this->middleware(),
            $this->container(),
        );
    }

    protected function tearDown(): void
    {
        $this->client = null;
        parent::tearDown();
    }

    /** @param array<string,string> $headers */
    protected function get(string $uri, array $headers = []): TestResponse { return $this->client()->get($uri, $headers); }
    protected function head(string $uri, array $headers = []): TestResponse { return $this->client()->head($uri, $headers); }
    protected function options(string $uri, array $headers = []): TestResponse { return $this->client()->options($uri, $headers); }
    protected function delete(string $uri, array $headers = []): TestResponse { return $this->client()->delete($uri, $headers); }

    /** @param array<string,mixed> $body */
    protected function post(string $uri, array $body = [], array $headers = []): TestResponse { return $this->client()->post($uri, $body, $headers); }
    protected function put(string $uri, array $body = [], array $headers = []): TestResponse { return $this->client()->put($uri, $body, $headers); }
    protected function patch(string $uri, array $body = [], array $headers = []): TestResponse { return $this->client()->patch($uri, $body, $headers); }

    /** @param array<string,string> $headers */
    protected function getJson(string $uri, array $headers = []): TestResponse { return $this->client()->getJson($uri, $headers); }
    protected function postJson(string $uri, array $data = [], array $headers = []): TestResponse { return $this->client()->postJson($uri, $data, $headers); }
    protected function putJson(string $uri, array $data = [], array $headers = []): TestResponse { return $this->client()->putJson($uri, $data, $headers); }
    protected function patchJson(string $uri, array $data = [], array $headers = []): TestResponse { return $this->client()->patchJson($uri, $data, $headers); }
    protected function deleteJson(string $uri, array $data = [], array $headers = []): TestResponse { return $this->client()->deleteJson($uri, $data, $headers); }
}
