<?php
declare(strict_types=1);

namespace NeoFramework\Core\Attributes;

/**
 * Prefixo de path e, opcionalmente, de nome para todas as rotas da classe.
 *
 * ```php
 * #[RoutePrefix('/api/v1', name: 'api.v1.')]
 * ```
 *
 * O prefixo de nome é concatenado cru, sem separador implícito: quem escreve
 * decide se o separador é `.`, `:` ou nada. Inventar um `.` aqui obrigaria quem
 * usa outra convenção a lutar contra o framework.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class RoutePrefix
{
    public function __construct(public string $prefix, public string $name = '') {}
}
