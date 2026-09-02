<?php

declare(strict_types=1);

namespace NeoFramework\Core\Events;

use NeoFramework\Core\Routing\RouteDefinition;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A action vai ser chamada — autenticação, autorização e bind já passaram.
 *
 * Separado de `RouteMatched` porque o intervalo entre os dois inclui guard,
 * policy e binding de DTO. Medir a partir do casamento da rota atribuiria esse
 * tempo ao controller, e é justamente aí que uma policy lenta se esconde.
 */
final readonly class ControllerInvoking
{
    public function __construct(
        public ServerRequestInterface $request,
        public RouteDefinition $route,
    ) {
    }
}
