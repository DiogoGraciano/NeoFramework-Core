<?php
declare(strict_types=1);
namespace NeoFramework\Core\Http;

interface RequestScopeInterface { public function set(string $id, mixed $value): void; public function get(string $id, mixed $default = null): mixed; public function has(string $id): bool; public function reset(): void; }
