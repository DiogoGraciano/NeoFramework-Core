<?php
declare(strict_types=1);

/**
 * A engine de template resolve modifiers ({var|funcao!arg}) em app\helpers\Functions,
 * que e uma classe da aplicacao. Este arquivo fornece uma implementacao minima para
 * os testes do pacote, que nao tem uma aplicacao em volta.
 *
 * Nao e carregado pelo PHPUnit automaticamente: o scan de testes procura o sufixo
 * Test.php. E incluido explicitamente por NeoFrameworkTemplateTest.
 */

namespace app\helpers;

if (!\class_exists(Functions::class, false)) {
    class Functions
    {
        /** Sem varargs de proposito: detecta argumento extra vazando do parser. */
        public static function upper(string $value): string
        {
            return \strtoupper($value);
        }

        /** Com argumento: detecta o nome do modifier sendo passado como parametro. */
        public static function cut(string $value, int|string $length): string
        {
            return \substr($value, 0, (int) $length);
        }
    }
}
