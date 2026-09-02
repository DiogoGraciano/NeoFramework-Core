<?php
declare(strict_types=1);
namespace NeoFramework\Core\Config;

final readonly class TemplateConfig { private function __construct(public bool $cache) {} public static function from(ConfigRepositoryInterface $c): self { return new self(ConfigValidator::bool($c, 'template.cache', true)); } }
