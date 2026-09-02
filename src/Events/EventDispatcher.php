<?php

declare(strict_types=1);

namespace NeoFramework\Core\Events;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\EventDispatcher\StoppableEventInterface;

/** Dispatcher síncrono mínimo. Sem listener, o custo é uma iteração vazia. */
final readonly class EventDispatcher implements EventDispatcherInterface
{
    public function __construct(private ListenerProviderInterface $listeners)
    {
    }

    /** Existe para quem precisa registrar um listener por requisição, como o profiler. */
    public function provider(): ListenerProviderInterface
    {
        return $this->listeners;
    }

    public function dispatch(object $event): object
    {
        foreach ($this->listeners->getListenersForEvent($event) as $listener) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }

            $listener($event);
        }

        return $event;
    }
}
