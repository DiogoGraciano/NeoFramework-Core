<?php
declare(strict_types=1);

namespace NeoFramework\Core;

use InvalidArgumentException;
use NeoFramework\Core\Attributes\Rule;
use NeoFramework\Core\Validation\RespectValidator;
use NeoFramework\Core\Validation\ValidationRule;
use NeoFramework\Core\Validation\ValidatorInterface;

/**
 * Validação de arrays avulsos, fora do caminho de DTOs.
 *
 * Usa o mesmo motor que o `DtoBinder`, então uma regra vale o mesmo nos dois
 * lugares. A aplicação nunca importa a biblioteca de validação: declara a regra
 * pelo nome ou por `#[Rule]`, e o motor traduz.
 *
 * ```php
 * $result = (new Validator())->make(
 *     ['email' => $email, 'idade' => $idade],
 *     ['email' => 'email', 'idade' => new Rule('between', [18, 120])],
 * );
 * ```
 */
class Validator
{
    private bool $hasErrors = false;

    /** @var array<string,list<string>> */
    private array $errors = [];

    public function __construct(private readonly ValidatorInterface $validator = new RespectValidator())
    {
    }

    /**
     * @param array<string,mixed> $fields
     * @param array<string,mixed> $rules regra, nome de regra ou lista de qualquer um dos dois, por campo
     * @param array<string,string> $messages substitui a mensagem do motor, por campo
     */
    public function make(array $fields, array $rules, array $messages = []): self
    {
        $errors = [];

        foreach ($rules as $field => $declared) {
            $found = $this->validator->validate($fields[$field] ?? null, self::rulesFor($field, $declared), $field);
            if ($found === []) continue;

            $errors[$field] = isset($messages[$field]) ? [$messages[$field]] : $found;
        }

        $this->errors = $errors;
        $this->hasErrors = $errors !== [];

        return $this;
    }

    public function hasError(): bool
    {
        return $this->hasErrors;
    }

    /** @return array<string,list<string>> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /** @return list<ValidationRule> */
    private static function rulesFor(string $field, mixed $declared): array
    {
        if (is_array($declared)) {
            return array_merge(...array_map(static fn (mixed $rule): array => self::rulesFor($field, $rule), $declared));
        }

        return [match (true) {
            $declared instanceof ValidationRule => $declared,
            is_string($declared) => new Rule($declared),
            default => throw new InvalidArgumentException("As regras do campo '{$field}' devem ser uma regra, um nome de regra ou uma lista deles."),
        }];
    }
}
