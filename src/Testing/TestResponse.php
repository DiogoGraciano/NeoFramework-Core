<?php

declare(strict_types=1);

namespace NeoFramework\Core\Testing;

use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;

/**
 * Envelope de asserções sobre uma resposta PSR-7.
 *
 * Deliberadamente NÃO estende a Response de produção: um objeto de teste que é
 * também o objeto real acaba ganhando conveniências que vazam para a aplicação.
 */
final class TestResponse
{
    public function __construct(private readonly ResponseInterface $response)
    {
    }

    public function psr(): ResponseInterface
    {
        return $this->response;
    }

    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    public function body(): string
    {
        $body = $this->response->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        return (string) $body;
    }

    public function header(string $name): string
    {
        return $this->response->getHeaderLine($name);
    }

    /**
     * Corpo decodificado como JSON. Com $path, navega por notação de ponto:
     * `data.0.email`.
     */
    public function json(?string $path = null): mixed
    {
        $decoded = json_decode($this->body(), true);

        if ($path === null) {
            return $decoded;
        }

        $current = $decoded;

        foreach (explode('.', $path) as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
                continue;
            }

            return null;
        }

        return $current;
    }

    // ----- status -----------------------------------------------------------

    public function assertStatus(int $expected): self
    {
        Assert::assertSame($expected, $this->status(), sprintf(
            "Esperava status %d, veio %d.\nCorpo: %s",
            $expected,
            $this->status(),
            $this->excerpt()
        ));

        return $this;
    }

    public function assertOk(): self { return $this->assertStatus(200); }
    public function assertCreated(): self { return $this->assertStatus(201); }
    public function assertNoContent(): self { return $this->assertStatus(204); }
    public function assertBadRequest(): self { return $this->assertStatus(400); }
    public function assertUnauthorized(): self { return $this->assertStatus(401); }
    public function assertForbidden(): self { return $this->assertStatus(403); }
    public function assertNotFound(): self { return $this->assertStatus(404); }
    public function assertMethodNotAllowed(): self { return $this->assertStatus(405); }
    public function assertServerError(): self { return $this->assertStatus(500); }

    public function assertSuccessful(): self
    {
        Assert::assertTrue(
            $this->status() >= 200 && $this->status() < 300,
            sprintf("Esperava status 2xx, veio %d.\nCorpo: %s", $this->status(), $this->excerpt())
        );

        return $this;
    }

    /** @param list<string> $methods */
    public function assertAllows(array $methods): self
    {
        $allowed = array_values(array_filter(array_map(trim(...), explode(',', $this->header('Allow')))));
        sort($allowed);
        sort($methods);
        Assert::assertSame($methods, $allowed, 'Header Allow diferente do esperado.');

        return $this;
    }

    // ----- headers ----------------------------------------------------------

    public function assertHeader(string $name, ?string $value = null): self
    {
        Assert::assertTrue($this->response->hasHeader($name), "Header \"{$name}\" ausente.");

        if ($value !== null) {
            Assert::assertSame($value, $this->header($name), "Header \"{$name}\" com valor inesperado.");
        }

        return $this;
    }

    public function assertHeaderMissing(string $name): self
    {
        Assert::assertFalse($this->response->hasHeader($name), "Header \"{$name}\" deveria estar ausente.");

        return $this;
    }

    public function assertContentType(string $expected): self
    {
        $actual = $this->header('Content-Type');
        $matchesJsonSuffix = $expected === 'application/json' && str_ends_with(strtolower(strtok($actual, ';') ?: ''), '+json');
        Assert::assertTrue($matchesJsonSuffix || str_contains($actual, $expected), 'Content-Type inesperado.');

        return $this;
    }

    public function assertRedirect(?string $to = null): self
    {
        Assert::assertTrue(
            in_array($this->status(), [301, 302, 303, 307, 308], true),
            sprintf('Esperava um redirect, veio %d.', $this->status())
        );

        if ($to !== null) {
            Assert::assertSame($to, $this->header('Location'), 'Destino do redirect inesperado.');
        }

        return $this;
    }

    public function assertCookie(string $name, ?string $value = null): self
    {
        $cookies = CookieJar::parse($this->response->getHeader('Set-Cookie'));

        Assert::assertArrayHasKey($name, $cookies, "Cookie \"{$name}\" não foi enviado.");

        if ($value !== null) {
            Assert::assertSame($value, $cookies[$name], "Cookie \"{$name}\" com valor inesperado.");
        }

        return $this;
    }

    // ----- corpo ------------------------------------------------------------

    public function assertBodySame(string $expected): self
    {
        Assert::assertSame($expected, $this->body());

        return $this;
    }

    public function assertBodyContains(string $needle): self
    {
        Assert::assertStringContainsString($needle, $this->body());

        return $this;
    }

    /** @param array<mixed> $expected Subconjunto que o JSON precisa conter. */
    public function assertJson(array $expected): self
    {
        $actual = $this->json();

        Assert::assertIsArray($actual, "O corpo não é JSON.\nCorpo: " . $this->excerpt());

        foreach ($expected as $key => $value) {
            Assert::assertArrayHasKey($key, $actual, "Chave \"{$key}\" ausente no JSON.");
            Assert::assertSame($value, $actual[$key], "Valor de \"{$key}\" inesperado.");
        }

        return $this;
    }

    public function assertJsonPath(string $path, mixed $expected): self
    {
        Assert::assertSame($expected, $this->json($path), "Caminho \"{$path}\" com valor inesperado.\nCorpo: " . $this->excerpt());

        return $this;
    }

    public function assertJsonCount(int $expected, ?string $path = null): self
    {
        $value = $this->json($path);

        Assert::assertIsArray($value, 'O caminho não aponta para um array.');
        Assert::assertCount($expected, $value);

        return $this;
    }

    /** Trecho do corpo para a mensagem de falha, sem despejar megabytes. */
    private function excerpt(): string
    {
        $body = $this->body();

        return strlen($body) > 300 ? substr($body, 0, 300) . '…' : $body;
    }
}
