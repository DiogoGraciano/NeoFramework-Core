<?php

declare(strict_types=1);

namespace NeoFramework\Core\Events;

use NeoFramework\Core\Http\RequestScopeContext;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Ponte para o dispatcher da requisição corrente.
 *
 * Vive no `RequestScope` — e não num singleton — para que um runtime
 * persistente não compartilhe listeners entre requisições. Sem dispatcher
 * registrado, despachar é um no-op: instrumentação desligada não pode alterar
 * o comportamento da aplicação.
 */
final class Events
{
    public const KEY = 'neoframework.events';

    private function __construct()
    {
    }

    public static function dispatch(object $event): object
    {
        return self::dispatcher()?->dispatch($event) ?? $event;
    }

    public static function dispatcher(): ?EventDispatcherInterface
    {
        $dispatcher = RequestScopeContext::current()?->get(self::KEY);

        return $dispatcher instanceof EventDispatcherInterface ? $dispatcher : null;
    }
}
