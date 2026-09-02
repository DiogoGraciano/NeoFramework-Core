<?php

declare(strict_types=1);

namespace NeoFramework\Core\Testing;

/**
 * Guarda os cookies entre requisições de um mesmo cliente.
 *
 * É o que permite testar um fluxo com sessão: a resposta que define o cookie e
 * a requisição seguinte que o apresenta.
 */
final class CookieJar
{
    /** @var array<string,string> */
    private array $cookies = [];

    /** @param array<string,string> $cookies */
    public function __construct(array $cookies = [])
    {
        $this->cookies = $cookies;
    }

    /** @return array<string,string> */
    public function all(): array
    {
        return $this->cookies;
    }

    public function set(string $name, string $value): void
    {
        $this->cookies[$name] = $value;
    }

    /** @param list<string> $setCookieHeaders */
    public function absorb(array $setCookieHeaders): void
    {
        foreach (self::parse($setCookieHeaders) as $name => $value) {
            // Um Set-Cookie com valor vazio e Max-Age/Expires no passado é
            // remoção; tratar como atribuição deixaria o cookie "morto" viajando.
            if ($value === '') {
                unset($this->cookies[$name]);
                continue;
            }

            $this->cookies[$name] = $value;
        }
    }

    /**
     * Extrai apenas nome e valor de cada header Set-Cookie, ignorando atributos.
     *
     * @param list<string> $headers
     * @return array<string,string>
     */
    public static function parse(array $headers): array
    {
        $cookies = [];

        foreach ($headers as $header) {
            $pair = explode(';', $header, 2)[0];

            if (!str_contains($pair, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $pair, 2);
            $cookies[trim($name)] = rawurldecode(trim($value));
        }

        return $cookies;
    }
}
