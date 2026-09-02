<?php

declare(strict_types=1);

namespace NeoFramework\Core\Events;

/**
 * Uma leitura de cache, com o resultado.
 *
 * Um evento só, com `hit`, em vez de dois: quem mede taxa de acerto precisa dos
 * dois números vindos do mesmo contador, e dois eventos separados convidam a
 * registrar um listener só para o acerto e concluir que a taxa é 100%.
 *
 * A chave não entra: ela costuma carregar id de usuário ou de documento, e uma
 * métrica com cardinalidade ilimitada derruba o coletor antes de ser útil.
 */
final readonly class CacheAccessed
{
    public function __construct(public bool $hit)
    {
    }
}
