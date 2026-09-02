<?php

declare(strict_types=1);

namespace NeoFramework\Core\Events;

use NeoFramework\Core\Routing\RouteDefinition;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A action retornou, com o tempo gasto só dentro dela.
 *
 * `ResponseCreated` mede o ciclo inteiro; a diferença entre os dois é o custo
 * de middleware, guard e binding. Ter os dois é o que permite dizer se uma rota
 * lenta é lenta no controller ou antes dele.
 *
 * Não é despachado quando a action lança — nesse caso o evento é
 * `ExceptionRaised`, e anunciar "invoked" com duração de um trabalho que não
 * terminou poluiria qualquer média.
 */
final readonly class ControllerInvoked
{
    public function __construct(
        public ServerRequestInterface $request,
        public RouteDefinition $route,
        public float $durationMs,
    ) {
    }
}
