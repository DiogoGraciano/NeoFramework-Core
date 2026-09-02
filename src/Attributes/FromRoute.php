<?php
declare(strict_types=1);
namespace NeoFramework\Core\Attributes;

#[\Attribute(\Attribute::TARGET_PARAMETER)] final readonly class FromRoute { public function __construct(public ?string $key = null) {} }
