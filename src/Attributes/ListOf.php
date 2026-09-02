<?php
declare(strict_types=1);

namespace NeoFramework\Core\Attributes;

/** Declara o tipo de cada elemento de um parâmetro `array` de um DTO. */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class ListOf
{
    /** @param string $type Tipo escalar, enum, data ou DTO readonly de cada elemento. */
    public function __construct(public string $type) {}
}
