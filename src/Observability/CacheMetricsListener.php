<?php

declare(strict_types=1);

namespace NeoFramework\Core\Observability;

use NeoFramework\Core\Events\CacheAccessed;

/**
 * Taxa de acerto do cache.
 *
 * Um contador só, com o label `result`, porque acerto e erro só significam algo
 * um em relação ao outro: dois contadores separados permitem alguém coletar só
 * o de acerto e concluir que a taxa é 100%.
 *
 * A chave nunca vira label — ela carrega id de usuário ou de documento, e a
 * cardinalidade derrubaria o coletor.
 */
final readonly class CacheMetricsListener
{
    public const ACCESSES = 'cache.accesses';

    public function __construct(private MetricsExporterInterface $metrics)
    {
    }

    public function __invoke(object $event): void
    {
        if (!$event instanceof CacheAccessed) return;

        $this->metrics->counter(self::ACCESSES, 1, ['result' => $event->hit ? 'hit' : 'miss']);
    }
}
