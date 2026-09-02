<?php
declare(strict_types=1);

namespace NeoFramework\Core\Routing;

final readonly class Matcher
{
    public function __construct(private array $compiled) {}

    public function match(string $method, string $path, ?string $host = null): MatchResult
    {
        $method = strtoupper($method);
        $path = self::normalize($path);
        $host = self::normalizeHost($host);

        foreach ($method === 'HEAD' ? ['HEAD', 'GET'] : [$method] as $candidate) {
            // Restrita a host primeiro: ela é mais específica que a rota de
            // mesmo path que atende qualquer host, e testar o balde comum antes
            // faria a genérica engolir a restrita.
            foreach ($this->compiled['staticHost'][$candidate][$path] ?? [] as $record) {
                $hostVars = self::hostVariables($record, $host);
                if ($hostVars === null) continue;

                return MatchResult::found(RouteDefinition::fromArray($record), [...($record['defaults'] ?? []), ...$hostVars]);
            }

            $record = $this->compiled['static'][$candidate][$path] ?? null;
            if ($record !== null) return MatchResult::found(RouteDefinition::fromArray($record), $record['defaults'] ?? []);

            foreach ($this->compiled['dynamic'][$candidate] ?? [] as $record) {
                $hostVars = self::hostVariables($record, $host);
                if ($hostVars === null) continue;

                if (($matches = self::matches($record['regex'], $path)) === null) continue;

                $vars = [];
                // Decodificar só aqui: o valor capturado pode conter %2F sem
                // que isso vire uma fronteira de segmento.
                foreach ($record['variables'] as $name) if (isset($matches[$name]) && $matches[$name] !== '') $vars[$name] = rawurldecode($matches[$name]);

                // Os defaults entram por baixo: um valor presente na URL sempre
                // vence, senão um placeholder opcional preenchido pelo cliente
                // seria sobrescrito pelo default.
                return MatchResult::found(RouteDefinition::fromArray($record), [...($record['defaults'] ?? []), ...$hostVars, ...$vars]);
            }
        }

        $allowed = [];
        foreach (array_unique(array_merge(array_keys($this->compiled['static']), array_keys($this->compiled['staticHost'] ?? []), array_keys($this->compiled['dynamic']))) as $candidate) {
            if (isset($this->compiled['static'][$candidate][$path])) $allowed[] = $candidate;
            foreach ($this->compiled['staticHost'][$candidate][$path] ?? [] as $record) {
                if (self::hostVariables($record, $host) !== null) $allowed[] = $candidate;
            }
            foreach ($this->compiled['dynamic'][$candidate] ?? [] as $record) {
                // O host também filtra o Allow: anunciar POST de um host que não
                // atende esta requisição é informação errada para o cliente.
                if (self::hostVariables($record, $host) !== null && self::matches($record['regex'], $path) !== null) $allowed[] = $candidate;
            }
        }
        if (in_array('GET', $allowed, true)) $allowed[] = 'HEAD';

        if ($allowed === []) {
            // Só aqui: o fallback substitui o 404, nunca o 405. Um método errado
            // numa rota que existe continua sendo 405 — transformá-lo em 404
            // esconderia do cliente que o recurso existe.
            foreach ($method === 'HEAD' ? ['HEAD', 'GET'] : [$method] as $candidate) {
                $record = $this->compiled['fallback'][$candidate] ?? null;
                if ($record !== null) return MatchResult::found(RouteDefinition::fromArray($record), $record['defaults'] ?? []);
            }

            return MatchResult::notFound();
        }

        $allowed[] = 'OPTIONS';

        // Um preflight de CORS chega como OPTIONS numa rota que só declara POST.
        // Responder 405 ali quebra a chamada real que viria depois, e obrigar
        // cada controller a declarar OPTIONS é ruído em toda action.
        return $method === 'OPTIONS' ? MatchResult::options($allowed) : MatchResult::methodNotAllowed($allowed);
    }

    /**
     * Variáveis capturadas do host, ou null quando a rota não atende este host.
     *
     * Rota sem host casa qualquer um — é o comportamento de antes de
     * `#[RouteHost]` existir, e continua sendo o padrão.
     *
     * @return array<string,string>|null
     */
    private static function hostVariables(array $record, ?string $host): ?array
    {
        $regex = $record['hostRegex'] ?? null;
        if ($regex === null) return [];

        // Rota restrita a host, requisição sem host conhecido: recusar. Aceitar
        // aqui faria a restrição sumir sempre que o Host não chegasse.
        if ($host === null) return null;

        $matches = self::matches($regex, $host);
        if ($matches === null) return null;

        $vars = [];
        foreach ($record['hostVariables'] ?? [] as $name) if (isset($matches[$name]) && $matches[$name] !== '') $vars[$name] = $matches[$name];

        return $vars;
    }

    /** A porta não faz parte do padrão de host: `example.com` casa `example.com:8080`. */
    private static function normalizeHost(?string $host): ?string
    {
        if ($host === null || $host === '') return null;

        $host = strtolower($host);
        $position = strrpos($host, ':');
        // Só corta quando o que vem depois é porta: um IPv6 literal tem vários
        // ":" e nenhum deles separa porta fora de colchetes.
        if ($position !== false && !str_contains($host, ']') && ctype_digit(substr($host, $position + 1))) $host = substr($host, 0, $position);

        return rtrim($host, '.');
    }

    /**
     * Normaliza o path para casamento.
     *
     * Percent-escapes são resolvidos para que uma rota literal acentuada case,
     * mas %2F e %5C ficam intactos: decodificá-los aqui deixaria o cliente
     * forjar uma fronteira de segmento e alcançar uma rota de outro nível
     * ("/users/admin%2Fdelete" viraria "/users/admin/delete").
     */
    private static function normalize(string $path): string
    {
        $path = preg_replace_callback('/%(?!2[Ff]|5[Cc])[0-9A-Fa-f]{2}/', static fn(array $m): string => rawurldecode($m[0]), $path) ?? $path;
        $path = preg_replace('#/{2,}#', '/', $path) ?? $path;
        return $path === '' ? '/' : ($path === '/' ? '/' : rtrim($path, '/'));
    }

    /**
     * preg_match devolve false em erro, e false é falsy — um estouro de
     * backtrack viraria "rota não encontrada" em silêncio.
     */
    private static function matches(string $regex, string $path): ?array
    {
        $result = preg_match($regex, $path, $matches);
        if ($result === false || preg_last_error() !== PREG_NO_ERROR) {
            throw new \RuntimeException("Falha ao casar a rota \"{$regex}\": " . preg_last_error_msg());
        }
        return $result === 1 ? $matches : null;
    }
}
