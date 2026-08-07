<?php

namespace NeoFramework\Core;

use DI\Container as DIContainer;
use DI\ContainerBuilder;
use NeoFramework\Core\Functions;

class Container
{
    /**
     * O container é caro de construir (varre atributos e monta o autowiring).
     * Reconstruí-lo a cada get() jogava fora todo o trabalho e quebrava
     * qualquer definição com escopo de singleton.
     */
    private static ?DIContainer $instance = null;

    public function load():DIContainer
    {
        if (self::$instance === null) {
            $builder = new ContainerBuilder();
            $builder->useAttributes(true);
            $builder->useAutowiring(true);

            if (env("ENVIRONMENT") === "prod") {
                $cacheDir = Functions::getRoot() . "Cache" . DIRECTORY_SEPARATOR . "container";

                if (!is_dir($cacheDir)) {
                    @mkdir($cacheDir, 0775, true);
                }

                if (is_dir($cacheDir) && is_writable($cacheDir)) {
                    $builder->enableCompilation($cacheDir);
                    $builder->writeProxiesToFile(true, $cacheDir . DIRECTORY_SEPARATOR . "proxies");
                }
            }

            self::$instance = $builder->build();
        }

        return self::$instance;
    }

    public function get(string $id){
        return $this->load()->get($id);
    }

    /**
     * Descarta o container. Útil em testes.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
