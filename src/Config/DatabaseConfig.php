<?php
declare(strict_types=1);
namespace NeoFramework\Core\Config;

final readonly class DatabaseConfig { private function __construct(public string $driver) {} public static function from(ConfigRepositoryInterface $c): self { return new self(ConfigValidator::string($c, 'database.driver')); } }
