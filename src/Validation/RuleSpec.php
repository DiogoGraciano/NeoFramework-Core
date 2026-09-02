<?php
declare(strict_types=1);

namespace NeoFramework\Core\Validation;

/**
 * Como uma regra é expressa para o motor de validação: nome e argumentos.
 *
 * É o que mantém os atributos independentes da biblioteca que valida. O atributo
 * declara `email`; quem sabe traduzir isso é o `ValidatorInterface`.
 */
final readonly class RuleSpec
{
    /** @param list<mixed> $arguments */
    public function __construct(public string $name, public array $arguments = [], public ?string $message = null) {}
}
