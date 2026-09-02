<?php
declare(strict_types=1);

namespace NeoFramework\Core\Testing;

use PHPUnit\Framework\Assert;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Dispatcher que grava em vez de entregar.
 *
 * Afirmar sobre efeito colateral de listener é indireto e frágil: o teste passa
 * a depender do que o listener faz, e não do que o código sob teste decidiu.
 * Aqui a afirmação é sobre o evento em si.
 */
final class FakeDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    private array $dispatched = [];

    public function dispatch(object $event): object
    {
        $this->dispatched[] = $event;

        return $event;
    }

    /**
     * @template T of object
     * @param class-string<T> $event
     * @param callable(T):bool|null $filter
     */
    public function assertDispatched(string $event, ?callable $filter = null): void
    {
        foreach ($this->dispatched as $dispatched) {
            if (!$dispatched instanceof $event) continue;
            if ($filter === null || $filter($dispatched)) return;
        }

        Assert::fail("Evento {$event} não foi despachado" . ($filter === null ? '.' : ' com o conteúdo esperado.'));
    }

    /**
     * O casamento é por `instanceof`, não por nome: um evento que estende o
     * proibido continua sendo o evento proibido.
     *
     * @param class-string $event
     */
    public function assertNotDispatched(string $event): void
    {
        $matches = array_values(array_filter($this->dispatched, static fn (object $e): bool => $e instanceof $event));

        Assert::assertCount(0, $matches, "Evento {$event} foi despachado e não deveria.");
    }

    /** @param class-string $event */
    public function assertDispatchedTimes(string $event, int $times): void
    {
        $actual = count(array_filter($this->dispatched, static fn (object $e): bool => $e instanceof $event));

        Assert::assertSame($times, $actual, "Esperado {$times} evento(s) {$event}, encontrado {$actual}.");
    }

    public function assertNothingDispatched(): void
    {
        Assert::assertSame([], $this->classesDispatched(), 'Nenhum evento deveria ter sido despachado.');
    }

    /** @return list<object> */
    public function dispatched(): array
    {
        return $this->dispatched;
    }

    /** @return list<string> */
    public function classesDispatched(): array
    {
        return array_map(static fn (object $e): string => $e::class, $this->dispatched);
    }
}
