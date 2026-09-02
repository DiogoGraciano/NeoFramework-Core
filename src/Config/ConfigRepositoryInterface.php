<?php
declare(strict_types=1);

namespace NeoFramework\Core\Config;

/** Read-only configuration assembled during bootstrap. */
interface ConfigRepositoryInterface
{
    public function get(string $key, mixed $default = null): mixed;

    public function require(string $key): mixed;

    /** @return array<string,mixed> */
    public function all(): array;
}
