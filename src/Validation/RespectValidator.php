<?php
declare(strict_types=1);

namespace NeoFramework\Core\Validation;

use LogicException;
use Respect\Validation\Exceptions\ComponentException;
use Respect\Validation\Exceptions\NestedValidationException;
use Respect\Validation\Factory;
use Respect\Validation\Validator as v;

/**
 * Motor padrão, apoiado no catálogo da Respect Validation.
 *
 * A biblioteca decide o booleano e escreve a mensagem; ela não aparece na
 * assinatura de nada que a aplicação escreva. Trocar de motor é implementar
 * `ValidatorInterface` — os DTOs não mudam.
 */
final class RespectValidator implements ValidatorInterface
{
    /**
     * Publica as regras do Core no catálogo, para que `UniqueDb` e `ExistsDb`
     * sejam alcançáveis pelo nome como qualquer outra.
     */
    public static function registerCoreRules(): void
    {
        Factory::setDefaultInstance(
            (new Factory())
                ->withRuleNamespace('NeoFramework\\Core\\Validator\\Rules')
                ->withExceptionNamespace('NeoFramework\\Core\\Validator\\Exception')
        );
    }

    public function validate(mixed $value, array $rules, string $field): array
    {
        $messages = [];

        foreach ($rules as $rule) {
            $spec = $rule->spec();

            try {
                // `setName()` é o que mantém o valor recebido fora da mensagem:
                // sem nome, a Respect usa o próprio input como rótulo — e o campo
                // pode ser uma senha.
                v::__callStatic($spec->name, $spec->arguments)->setName($field)->assert($value);
            } catch (NestedValidationException $failure) {
                $messages = [...$messages, ...($spec->message !== null ? [$spec->message] : array_values($failure->getMessages()))];
            } catch (ComponentException $error) {
                // Regra inexistente ou mal parametrizada é erro de quem escreveu o
                // atributo, não entrada inválida do cliente: não vira 422.
                throw new LogicException("A regra de validação \"{$spec->name}\" declarada em \"{$field}\" não existe ou recebeu argumentos inválidos.", 0, $error);
            }
        }

        return $messages;
    }
}
