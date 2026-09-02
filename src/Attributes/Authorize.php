<?php
declare(strict_types=1);

namespace NeoFramework\Core\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final readonly class Authorize
{
    /** `$subject` é o nome de um parâmetro da action ou de uma variável de rota. */
    public function __construct(public string $ability, public ?string $subject = null) {}
}
