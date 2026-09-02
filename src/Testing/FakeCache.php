<?php
declare(strict_types=1);

namespace NeoFramework\Core\Testing;

use NeoFramework\Core\Cache;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Cache em memória, instalado no lugar do configurado.
 *
 * O adapter padrão é o de sistema de arquivos: uma suíte que o use grava em
 * `Cache/` e leva o resultado de uma execução para a seguinte — o tipo de
 * acoplamento que faz um teste passar sozinho e falhar na suíte, ou o inverso.
 */
final class FakeCache
{
    private function __construct()
    {
    }

    /** Instala o pool em memória e devolve-o, para inspeção. */
    public static function install(): TagAwareCacheInterface
    {
        $cache = new TagAwareAdapter(new ArrayAdapter());
        Cache::swap($cache);

        return $cache;
    }

    /** Devolve o cache configurado. Chame no `tearDown`. */
    public static function uninstall(): void
    {
        Cache::swap(null);
    }
}
