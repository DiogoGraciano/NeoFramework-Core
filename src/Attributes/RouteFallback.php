<?php
declare(strict_types=1);

namespace NeoFramework\Core\Attributes;

/**
 * Action executada quando nenhuma rota casa.
 *
 * Substitui o 404 genérico por uma resposta da aplicação — uma página de erro
 * com layout, um redirecionamento de URL antiga, ou um Problem Details com o
 * corpo que a API documenta.
 *
 * Só entra em cena depois que todas as rotas falharam, então não sombreia nada.
 * Não afeta 405: um método errado numa rota que existe continua sendo 405, e
 * transformá-lo em 404 esconderia do cliente que o recurso existe.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class RouteFallback
{
    /** @param list<string> $methods */
    public function __construct(public array $methods = ['GET']) {}
}
