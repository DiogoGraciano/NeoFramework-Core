<?php

declare(strict_types=1);

namespace NeoFramework\Core\Routing;

/**
 * Encontra rotas dinâmicas que nunca serão alcançadas.
 *
 * Uma rota é inalcançável quando **todo** path que ela casaria já é casado por
 * outra declarada antes. Sem esta checagem a segunda rota simplesmente não roda,
 * sem erro nenhum — a mesma classe de falha do placeholder compilado como
 * literal.
 *
 * O critério é deliberadamente conservador: só reporta quando a cobertura é
 * demonstrável segmento a segmento. Sobreposição parcial não é reportada, porque
 * `/users/{id:\d+}` antes de `/users/{slug}` é um desenho legítimo e comum — as
 * duas são alcançáveis, cada uma pelo seu conjunto de valores. Um detector que
 * gritasse ali seria desligado na primeira semana.
 *
 * Rota estática nunca é reportada: o matcher a consulta antes de qualquer
 * dinâmica, então ela é sempre alcançável.
 */
final class RouteConflictDetector
{
    private function __construct()
    {
    }

    /**
     * @param array{static:array<string,array<string,array<string,mixed>>>,dynamic:array<string,list<array<string,mixed>>>,named:array<string,array<string,mixed>>} $compiled
     * @return list<string> mensagens; lista vazia significa nenhum conflito
     */
    public static function detect(array $compiled): array
    {
        $conflicts = [];

        foreach ($compiled['dynamic'] as $method => $records) {
            foreach ($records as $index => $record) {
                $shadowed = self::segments((string) $record['path']);
                if ($shadowed === null) continue;

                for ($earlier = 0; $earlier < $index; $earlier++) {
                    // Hosts diferentes nunca se sombreiam: as duas rotas não
                    // disputam requisição nenhuma, e apontar conflito ali
                    // reprovaria `route:cache` num desenho correto.
                    if (($records[$earlier]['host'] ?? null) !== ($record['host'] ?? null)) continue;

                    $covering = self::segments((string) $records[$earlier]['path']);
                    if ($covering === null || !self::covers($covering, $shadowed)) continue;

                    $conflicts[] = sprintf(
                        '%s %s (%s) nunca é alcançada: %s %s (%s) casa tudo que ela casaria.',
                        $method,
                        $record['path'],
                        self::action($record),
                        $method,
                        $records[$earlier]['path'],
                        self::action($records[$earlier]),
                    );
                    break;
                }
            }
        }

        return $conflicts;
    }

    /** @param array<string,mixed> $record */
    private static function action(array $record): string
    {
        return $record['name'] !== null && $record['name'] !== '' ? (string) $record['name'] : $record['controller'] . '::' . $record['action'];
    }

    /**
     * Quebra o path em segmentos classificados.
     *
     * Devolve null quando a rota tem parâmetro opcional: ele muda a quantidade de
     * segmentos que a rota casa, e comparar contagens deixaria de ser sólido.
     *
     * @return list<array{literal:bool,value:string}>|null
     */
    private static function segments(string $path): ?array
    {
        $segments = [];

        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '') continue;
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)(\?)?(?:\:((?:[^{}]|\{\d+(?:,\d*)?\})+))?\}$/', $segment, $match) !== 1) {
                // Segmento misto ("prefixo-{id}") ou literal.
                if (str_contains($segment, '{')) return null;
                $segments[] = ['literal' => true, 'value' => $segment];
                continue;
            }

            if (($match[2] ?? '') === '?') return null;

            $segments[] = ['literal' => false, 'value' => $match[3] ?? ''];
        }

        return $segments;
    }

    /**
     * @param list<array{literal:bool,value:string}> $covering
     * @param list<array{literal:bool,value:string}> $shadowed
     */
    private static function covers(array $covering, array $shadowed): bool
    {
        if (count($covering) !== count($shadowed)) return false;

        foreach ($covering as $index => $segment) {
            if (!self::segmentCovers($segment, $shadowed[$index])) return false;
        }

        return true;
    }

    /**
     * @param array{literal:bool,value:string} $covering
     * @param array{literal:bool,value:string} $shadowed
     */
    private static function segmentCovers(array $covering, array $shadowed): bool
    {
        if ($covering['literal']) return $shadowed['literal'] && $covering['value'] === $shadowed['value'];

        // Placeholder sem constraint casa qualquer segmento não vazio.
        if ($covering['value'] === '') return true;

        // Literal contra constraint: dá para decidir de verdade.
        if ($shadowed['literal']) return preg_match('~^(?:' . str_replace('~', '\\~', $covering['value']) . ')$~D', $shadowed['value']) === 1;

        // Duas constraints: só afirmamos cobertura quando são a mesma. Decidir
        // contenção entre regexes quaisquer não é viável, e errar aqui seria
        // recusar uma aplicação correta.
        return $covering['value'] === $shadowed['value'];
    }
}
