<?php
declare(strict_types=1);

namespace NeoFramework\Core\Attributes;

use NeoFramework\Core\Validation\RuleSpec;
use NeoFramework\Core\Validation\ValidationRule;

/**
 * Declara qualquer regra do catálogo do motor de validação.
 *
 * ```php
 * #[Rule('cpf')]                          public string $documento,
 * #[Rule('between', [1, 5])]              public int $nota,
 * #[Rule('in', [['pix', 'boleto']], message: 'Forma de pagamento inválida.')] public string $pagamento,
 * ```
 *
 * Sem `message`, a mensagem vem do motor e cita o nome do campo — nunca o valor
 * recebido.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::IS_REPEATABLE)]
final readonly class Rule implements ValidationRule
{
    /** @param list<mixed> $arguments */
    public function __construct(public string $name, public array $arguments = [], public ?string $message = null) {}

    public function spec(): RuleSpec
    {
        return new RuleSpec($this->name, $this->arguments, $this->message);
    }
}
