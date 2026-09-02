<?php

declare(strict_types=1);

namespace NeoFramework\Core\Events;

use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Monta o dispatcher a partir do container ou de `Config/events.php`.
 *
 * Vive fora do `HttpKernel` porque a CLI precisa do mesmo dispatcher: sem isso,
 * um evento despachado por `migration:up` ou por um worker de fila não chega a
 * listener nenhum — e o silêncio é indistinguível de "não aconteceu".
 */
final class DispatcherFactory
{
    private function __construct()
    {
    }

    public static function fromContainer(ContainerInterface $container): EventDispatcherInterface
    {
        if ($container->has(EventDispatcherInterface::class)) {
            $dispatcher = $container->get(EventDispatcherInterface::class);
            if ($dispatcher instanceof EventDispatcherInterface) return $dispatcher;
        }

        return new EventDispatcher(ListenerCache::provider($container));
    }
}
