<?php
declare(strict_types=1);

namespace NeoFramework\Core\Validation;

/**
 * Um atributo que declara uma regra de validação.
 *
 * O binder distingue regra de atributo de origem (`#[FromQuery]`) por este
 * contrato, e não por uma lista de classes conhecidas: uma regra de terceiro
 * funciona sem tocar no Core.
 */
interface ValidationRule
{
    public function spec(): RuleSpec;
}
