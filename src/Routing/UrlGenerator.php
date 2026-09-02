<?php

declare(strict_types=1);

namespace NeoFramework\Core\Routing;

use InvalidArgumentException;
use NeoFramework\Core\Http\BasePath;

final readonly class UrlGenerator
{
    public function __construct(private array $named, private string $basePath = '', private string $baseUrl = '')
    {
    }

    public function path(string $name, array $parameters = [], array $query = []): string
    {
        $route = $this->named[$name] ?? throw new InvalidArgumentException("Rota nomeada não encontrada: {$name}");
        $constraints = $route['constraints'] ?? [];

        $path = $this->expand($route['path'], $parameters, $constraints, $name, true);

        // Um opcional omitido deixa a barra que o antecedia; a forma canônica
        // não tem barra final, ainda que o matcher tolere as duas.
        $path = preg_replace('~/+~', '/', (string) $path) ?: '/';
        $path = $path === '/' ? '/' : (rtrim($path, '/') ?: '/');
        $query = array_merge($parameters, $query);
        $path = BasePath::prepend($path, $this->basePath);

        return $query ? $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : $path;
    }

    /**
     * URL absoluta.
     *
     * Necessária para e-mail, webhook e qualquer link que saia da aplicação — um
     * path relativo não resolve fora de um navegador que já está no site.
     */
    public function url(string $name, array $parameters = [], array $query = []): string
    {
        $route = $this->named[$name] ?? throw new InvalidArgumentException("Rota nomeada não encontrada: {$name}");
        $host = $route['host'] ?? null;

        if ($host === null) return $this->absolute($this->path($name, $parameters, $query));

        // Uma rota restrita por host precisa do host DELA, não do `app.url`:
        // gerar `https://example.com/painel` para uma rota que só responde em
        // `{tenant}.example.com` produz um link que dá 404 sem avisar ninguém.
        // `expand` consome os parâmetros por referência, então o que sobra para
        // o path tem de ser calculado antes — senão o valor do host reaparece
        // como query string.
        $hostKeys = array_flip($route['hostVariables'] ?? []);
        $hostParameters = array_intersect_key($parameters, $hostKeys);
        $resolved = $this->expand($host, $hostParameters, $route['constraints'] ?? [], $name, false);

        if ($this->baseUrl === '') throw new InvalidArgumentException("Uma URL absoluta exige 'app.url' configurado.");
        $scheme = parse_url($this->baseUrl, PHP_URL_SCHEME);

        return (is_string($scheme) ? $scheme : 'https') . '://' . $resolved
            . $this->path($name, array_diff_key($parameters, $hostKeys), $query);
    }

    public function absolute(string $path): string
    {
        if ($this->baseUrl === '') throw new InvalidArgumentException("Uma URL absoluta exige 'app.url' configurado.");

        return rtrim($this->baseUrl, '/') . $path;
    }

    /**
     * Substitui os placeholders de um padrão pelos parâmetros.
     *
     * `$encode` distingue path de host: um segmento de path é percent-encoded,
     * um rótulo de host não — `rawurlencode` num host transformaria um valor
     * inválido em literal aceito em vez de erro.
     *
     * @param array<string,mixed> $parameters consumido por referência; o que sobra vira query
     * @param array<string,string> $constraints
     */
    private function expand(string $pattern, array &$parameters, array $constraints, string $name, bool $encode): string
    {
        return (string) preg_replace_callback(
            '/\{([A-Za-z_][A-Za-z0-9_]*)(?:\:(?:[^{}]|\{\d+(?:,\d*)?\})+)?(\?)?\}/',
            function (array $match) use (&$parameters, $name, $constraints, $encode): string {
                $key = $match[1];
                if (!array_key_exists($key, $parameters)) {
                    if (($match[2] ?? '') === '?') return '';

                    throw new InvalidArgumentException("Parâmetro obrigatório '{$key}' ausente para a rota {$name}");
                }

                $value = (string) $parameters[$key];
                self::assertSatisfies($value, $constraints[$key] ?? null, $key, $name);
                unset($parameters[$key]);

                return $encode ? rawurlencode($value) : $value;
            },
            $pattern,
        );
    }

    /**
     * O valor gerado tem que casar a rota que o gerou.
     *
     * Sem esta checagem, `route('users.show', ['id' => 'abc'])` numa rota
     * `{id:\d+}` devolve `/users/abc` — um link que responde 404 e não avisa
     * nada a quem o gerou. O erro pertence a quem monta a URL, não a quem clica.
     */
    private static function assertSatisfies(string $value, ?string $constraint, string $key, string $route): void
    {
        if ($constraint === null) return;

        $pattern = '~^(?:' . $constraint . ')$~D';
        if (preg_match($pattern, $value) === 1) return;

        throw new InvalidArgumentException("O valor de '{$key}' não satisfaz a restrição da rota {$route}.");
    }
}
