<?php

declare(strict_types=1);

namespace NeoFramework\Core\Commands;

/**
 * Os códigos de saída dos comandos de migração.
 *
 * São contrato de CI, não decoração. `2` não é "erro pior que 1": é **preciso que você
 * decida** — um rename ambíguo, uma remoção a confirmar. A distinção existe para um script
 * de deploy poder tratar as duas situações de formas diferentes, o que é a razão de haver
 * códigos em vez de só sucesso e fracasso.
 */
final class ExitCode
{
    public const OK = 0;

    /** Falhou, ou há drift. */
    public const FAILURE = 1;

    /** Nada foi feito porque falta uma decisão humana. */
    public const NEEDS_DECISION = 2;

    private function __construct()
    {
    }
}
