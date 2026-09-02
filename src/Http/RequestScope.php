<?php
declare(strict_types=1);
namespace NeoFramework\Core\Http;

final class RequestScope implements RequestScopeInterface, ResettableInterface {
    /** @var array<string,mixed> */ private array $values = [];
    public function set(string $id, mixed $value): void { $this->values[$id] = $value; }
    public function get(string $id, mixed $default = null): mixed { return $this->values[$id] ?? $default; }
    public function has(string $id): bool { return array_key_exists($id, $this->values); }
    public function reset(): void { $this->values = []; }
}
