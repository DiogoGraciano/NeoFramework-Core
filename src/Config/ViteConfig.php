<?php
declare(strict_types=1);
namespace NeoFramework\Core\Config;

final readonly class ViteConfig { private function __construct(public bool $enabledInProduction) {} public static function from(ConfigRepositoryInterface $c): self { return new self(ConfigValidator::bool($c, 'vite.enabled_in_production')); } }
