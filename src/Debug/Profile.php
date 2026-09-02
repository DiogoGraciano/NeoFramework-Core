<?php

declare(strict_types=1);

namespace NeoFramework\Core\Debug;

/**
 * O que foi coletado de uma requisição.
 *
 * Guarda agregados, nunca conteúdo: sem corpo de requisição, sem headers, sem
 * SQL com valores ligados. Um profiler é um endpoint de leitura, e tudo que ele
 * armazena passa a ser legível por quem alcançar esse endpoint — inclusive
 * numa máquina de desenvolvimento com um túnel aberto.
 */
final readonly class Profile implements \JsonSerializable
{
    /** @param array<string,int> $cache @param array<string,array{count:int,durationMs:float}> $queries */
    public function __construct(
        public string $token,
        public string $method,
        public string $path,
        public ?string $route,
        public int $status,
        public float $durationMs,
        public float $controllerMs,
        public int $memoryPeakKb,
        public array $cache = [],
        public array $queries = [],
        public float $collectedAt = 0.0,
    ) {
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['token'],
            (string) $data['method'],
            (string) $data['path'],
            $data['route'] === null ? null : (string) $data['route'],
            (int) $data['status'],
            (float) $data['durationMs'],
            (float) $data['controllerMs'],
            (int) $data['memoryPeakKb'],
            $data['cache'] ?? [],
            $data['queries'] ?? [],
            (float) ($data['collectedAt'] ?? 0.0),
        );
    }
}
