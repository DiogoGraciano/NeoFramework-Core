<?php
declare(strict_types=1);

namespace NeoFramework\Core\Validation;

interface ValidatorInterface
{
    /**
     * @param list<ValidationRule> $rules
     * @return list<string> mensagens de erro; lista vazia significa válido
     */
    public function validate(mixed $value, array $rules, string $field): array;
}
