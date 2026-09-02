<?php
declare(strict_types=1);

namespace NeoFramework\Core\Attributes;

/**
 * Restringe as rotas ao host informado.
 *
 * ```php
 * #[RouteHost('{tenant}.example.com')]
 * ```
 *
 * Aceita a mesma gramática de placeholder do path, e as variáveis do host
 * chegam à action junto com as do path. A constraint padrão é `[^.]+`: um
 * `{tenant}` que casasse ponto faria `{tenant}.example.com` aceitar
 * `a.b.example.com`, alcançando de outro nível uma rota que se acredita restrita.
 *
 * Declarado no método, vence o da classe — o caso de uma action específica sair
 * do host do resto do controller.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class RouteHost
{
    public function __construct(public string $pattern) {}
}
