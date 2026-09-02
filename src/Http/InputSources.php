<?php
declare(strict_types=1);
namespace NeoFramework\Core\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Decide de ONDE vem cada parâmetro de um DTO.
 *
 * Sem um atributo de origem, a escolha é a heurística padrão — corpo
 * interpretado, senão corpo JSON, senão query string. Com `#[FromQuery]`,
 * `#[FromRoute]`, `#[FromBody]` ou `#[FromHeader]`, a origem é explícita e
 * vale só para aquele parâmetro, o que permite misturar fontes num mesmo DTO.
 */
final class InputSources
{
    /** @var array<string,mixed>|null */
    private ?array $body = null;

    /** @param array<string,string> $routeVariables */
    public function __construct(
        private readonly ServerRequestInterface $request,
        private readonly array $routeVariables = [],
    ) {
    }

    /** A origem já vem resolvida no plano: aqui não se lê atributo nenhum. */
    public function valueFor(DtoParameter $parameter): mixed
    {
        $key = $parameter->sourceKey ?? $parameter->name;

        return match ($parameter->source) {
            'header' => ($line = $this->request->getHeaderLine($key)) === '' ? null : $line,
            'query' => $this->request->getQueryParams()[$key] ?? null,
            'route' => $this->routeVariables[$key] ?? null,
            'body' => $this->body()[$key] ?? null,
            default => $this->default()[$parameter->name] ?? null,
        };
    }

    /** @return array<string,mixed> */
    private function body(): array
    {
        if ($this->body !== null) return $this->body;

        $parsed = $this->request->getParsedBody();
        if (is_array($parsed) && $parsed !== []) return $this->body = $parsed;

        $json = json_decode((string) $this->request->getBody(), true);

        return $this->body = is_array($json) ? $json : [];
    }

    /** @return array<string,mixed> */
    private function default(): array
    {
        $body = $this->body();

        return $body !== [] ? $body : $this->request->getQueryParams();
    }
}
