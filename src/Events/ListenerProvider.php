<?php

declare(strict_types=1);

namespace NeoFramework\Core\Events;

use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * Mapa de listeners por classe de evento.
 *
 * Listeners são registrados como class-string sempre que possível: só assim o
 * mapa é inspecionável por `event:list` e serializável. Um callable continua
 * aceito para registro programático, mas não aparece compilado.
 */
final class ListenerProvider implements ListenerProviderInterface
{
    /** @var array<class-string, list<array{listener: callable|class-string, priority: int, order: int}>> */
    private array $listeners = [];

    private int $sequence = 0;

    /**
     * Entradas já casadas e ordenadas, por classe de evento.
     *
     * O casamento percorre todos os tipos registrados fazendo `instanceof` e
     * depois ordena — trabalho idêntico a cada dispatch do mesmo evento. Num
     * worker que processa milhares de jobs isso se repete milhares de vezes.
     *
     * @var array<class-string, list<array{listener: callable|class-string, priority: int, order: int}>>
     */
    private array $matched = [];

    public function __construct(private readonly ?ContainerInterface $container = null)
    {
    }

    /**
     * Registra um listener.
     *
     * Falha aqui, e não no dispatch: um listener inválido descoberto durante
     * uma requisição é um erro que já custou caro.
     *
     * @param class-string $event
     */
    public function on(string $event, callable|string $listener, int $priority = 0): self
    {
        if (!class_exists($event) && !interface_exists($event)) {
            throw new \InvalidArgumentException("Evento desconhecido: {$event}.");
        }

        if (is_string($listener) && !is_callable($listener)) {
            if (!class_exists($listener)) {
                throw new \InvalidArgumentException("Listener desconhecido para {$event}: {$listener}.");
            }
            if (!method_exists($listener, '__invoke')) {
                throw new \InvalidArgumentException("Listener {$listener} precisa de __invoke().");
            }
        }

        $this->listeners[$event][] = ['listener' => $listener, 'priority' => $priority, 'order' => $this->sequence++];
        // Registrar depois de despachar é raro, mas invalidar é barato e evita
        // que o memo devolva uma lista desatualizada.
        $this->matched = [];

        return $this;
    }

    /**
     * Constrói a partir de `Config/events.php`.
     *
     * Formato: `[EventoX::class => [ListenerA::class, [ListenerB::class, 10]]]`,
     * onde o segundo elemento é a prioridade — maior roda antes.
     *
     * @param array<class-string, list<mixed>> $map
     */
    public static function fromConfig(array $map, ?ContainerInterface $container = null): self
    {
        $provider = new self($container);

        foreach ($map as $event => $listeners) {
            foreach ((array) $listeners as $listener) {
                if (is_array($listener)) {
                    $provider->on($event, $listener[0], (int) ($listener[1] ?? 0));
                    continue;
                }
                $provider->on($event, $listener);
            }
        }

        return $provider;
    }

    /** @return iterable<callable> */
    public function getListenersForEvent(object $event): iterable
    {
        foreach ($this->matchedFor($event) as $entry) {
            yield $this->resolve($entry['listener']);
        }
    }

    /** @return list<array{listener: callable|class-string, priority: int, order: int}> */
    private function matchedFor(object $event): array
    {
        $class = $event::class;
        if (isset($this->matched[$class])) return $this->matched[$class];

        $matched = [];

        foreach ($this->listeners as $type => $entries) {
            if (!$event instanceof $type) {
                continue;
            }
            foreach ($entries as $entry) {
                $matched[] = $entry;
            }
        }

        // Prioridade decrescente; empate resolve pela ordem de registro, para
        // que a sequência seja determinística e testável.
        usort($matched, static fn (array $a, array $b): int => [$b['priority'], -$a['order']] <=> [$a['priority'], -$b['order']]);

        return $this->matched[$class] = $matched;
    }

    /**
     * Mapa inspecionável: evento => listeners, em ordem de execução.
     *
     * @return array<class-string, list<string>>
     */
    public function registered(): array
    {
        $map = [];

        foreach ($this->listeners as $event => $entries) {
            usort($entries, static fn (array $a, array $b): int => [$b['priority'], -$a['order']] <=> [$a['priority'], -$b['order']]);
            $map[$event] = array_map(
                static fn (array $e): string => is_string($e['listener']) ? $e['listener'] : 'closure',
                $entries
            );
        }

        ksort($map);

        return $map;
    }

    /**
     * Mapa serializável, já validado e ordenado.
     *
     * Um listener registrado como closure não sobrevive a `var_export` e não
     * pode ser silenciosamente descartado: um listener ausente é indistinguível
     * de um listener que não fez nada.
     *
     * @return array<class-string, list<array{listener: class-string, priority: int}>>
     */
    public function compilable(): array
    {
        $map = [];

        foreach ($this->listeners as $event => $entries) {
            usort($entries, static fn (array $a, array $b): int => [$b['priority'], -$a['order']] <=> [$a['priority'], -$b['order']]);

            foreach ($entries as $entry) {
                if (!is_string($entry['listener'])) {
                    throw new \LogicException("O listener de {$event} é uma closure e não pode ser compilado. Registre-o como class-string em Config/events.php.");
                }
                $map[$event][] = ['listener' => $entry['listener'], 'priority' => $entry['priority']];
            }
        }

        ksort($map);

        return $map;
    }

    /**
     * Reconstrói a partir do mapa compilado, sem revalidar.
     *
     * A validação de `on()` é justamente o custo que a compilação existe para
     * eliminar: `class_exists` e `method_exists` por listener, a cada
     * requisição. O mapa só chega aqui depois de ter passado por ela.
     *
     * @param array<class-string, list<array{listener: class-string, priority: int}>> $map
     */
    public static function fromCompiled(array $map, ?ContainerInterface $container = null): self
    {
        $provider = new self($container);

        foreach ($map as $event => $entries) {
            foreach ($entries as $entry) {
                $provider->listeners[$event][] = [
                    'listener' => $entry['listener'],
                    'priority' => $entry['priority'],
                    'order' => $provider->sequence++,
                ];
            }
        }

        return $provider;
    }

    private function resolve(callable|string $listener): callable
    {
        if (is_callable($listener)) {
            return $listener;
        }

        $instance = $this->container?->has($listener) === true ? $this->container->get($listener) : new $listener();

        if (!is_callable($instance)) {
            throw new \LogicException("Listener {$listener} não é invocável.");
        }

        return $instance;
    }
}
