<?php
declare(strict_types=1);

namespace NeoFramework\Core\Routing;

/**
 * Compila o path — ou o host — de uma rota em PCRE.
 *
 * Gramática: {nome}, {nome:regex}, {nome?}, {nome?:regex}.
 *
 * O marcador de opcional vem depois do NOME, nunca no fim: "{id:\d+?}" é um
 * quantificador lazy válido, então um "?" final seria ambíguo.
 */
final class PatternCompiler
{
    /** Placeholder: nome, "?" opcional, e a constraint — que pode conter {n,m}. */
    private const PLACEHOLDER = '/\{([A-Za-z_][A-Za-z0-9_]*)(\?)?(?:\:((?:[^{}]|\{\d+(?:,\d*)?\})+))?\}/';

    public static function compile(string $path): array
    {
        if ($path === '' || $path[0] !== '/') throw new \InvalidArgumentException("A rota deve ser absoluta: {$path}");
        if ($path !== '/' && str_ends_with($path, '/')) $path = rtrim($path, '/');

        $compiled = self::build($path, '[^/]+', '/');

        return ['regex' => '~^' . $compiled['regex'] . '/?$~D', 'variables' => $compiled['variables'], 'constraints' => $compiled['constraints'], 'static' => !$compiled['dynamic'], 'path' => $path];
    }

    /**
     * Compila o host.
     *
     * A constraint padrão é `[^.]+` e não `[^/]+`: num host a fronteira é o
     * ponto, e `{sub}` casando `a.b` faria `{sub}.example.com` aceitar
     * `qualquer.coisa.example.com` — um subdomínio de outro nível alcançando
     * uma rota que se acredita restrita.
     *
     * O casamento é case-insensitive porque host não distingue caixa.
     */
    public static function compileHost(string $host): array
    {
        if ($host === '') throw new \InvalidArgumentException('O host da rota não pode ser vazio.');
        if (str_contains($host, '/')) throw new \InvalidArgumentException("O host da rota não pode conter '/': {$host}");

        $compiled = self::build($host, '[^.]+', '.');

        return ['regex' => '~^' . $compiled['regex'] . '$~Di', 'variables' => $compiled['variables'], 'constraints' => $compiled['constraints'], 'static' => !$compiled['dynamic'], 'host' => $host];
    }

    /**
     * Núcleo comum.
     *
     * `$separator` é o caractere que um placeholder opcional precisa absorver
     * quando o antecede — "/" no path, "." no host. Sem isso
     * "/posts/{slug}/{page?}" exigiria a barra final e nunca casaria "/posts/ola".
     */
    private static function build(string $subject, string $defaultConstraint, string $separator): array
    {
        $variables = [];
        $constraints = [];
        $offset = 0;
        $regex = '';
        $dynamic = false;

        preg_match_all(self::PLACEHOLDER, $subject, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $index => $whole) {
            [$token, $position] = $whole;
            $regex .= self::literal(substr($subject, $offset, $position - $offset), $subject);
            $name = $matches[1][$index][0];
            if (in_array($name, $variables, true)) throw new \InvalidArgumentException("Parâmetro de rota duplicado: {$name}");
            $variables[] = $name;
            $constraint = str_replace('~', '\\~', $matches[3][$index][0] ?: $defaultConstraint);
            // Guardado para o UrlGenerator: sem isto, gerar uma URL com um valor
            // que a rota não casa produz um link que responde 404 em silêncio.
            $constraints[$name] = $constraint;
            if (preg_match('/\(\?P?[<\']/', $constraint)) throw new \InvalidArgumentException("Grupos nomeados não são permitidos na constraint de \"{$name}\" em \"{$subject}\".");
            $capture = '(?P<' . $name . '>' . $constraint . ')';
            $quotedSeparator = preg_quote($separator, '~');
            if ($matches[2][$index][0] === '?' && str_ends_with($regex, $quotedSeparator)) {
                $regex = substr($regex, 0, -strlen($quotedSeparator)) . '(?:' . $quotedSeparator . $capture . ')?';
            } else {
                $regex .= $matches[2][$index][0] === '?' ? '(?:' . $capture . ')?' : $capture;
            }
            $offset = $position + strlen($token);
            $dynamic = true;
        }

        $regex .= self::literal(substr($subject, $offset), $subject);

        return ['regex' => $regex, 'variables' => $variables, 'constraints' => $constraints, 'dynamic' => $dynamic];
    }

    /**
     * Escapa um trecho literal.
     *
     * Uma chave sobrando aqui significa um placeholder que a gramática não
     * reconheceu. Compilar isso como literal produzia uma rota que nunca casa,
     * sem erro nenhum — a pior falha possível.
     */
    private static function literal(string $chunk, string $subject): string
    {
        if (str_contains($chunk, '{') || str_contains($chunk, '}')) {
            throw new \InvalidArgumentException("Placeholder inválido em \"{$subject}\": use {nome}, {nome:regex}, {nome?} ou {nome?:regex}.");
        }
        return preg_quote($chunk, '~');
    }
}
