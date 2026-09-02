<?php
declare(strict_types=1);

namespace NeoFramework\Core;

use NeoFramework\Core\Config\ConfigRepository;
use NeoFramework\Core\Config\ConfigRepositoryInterface;

/**
 * Transitional facade for static legacy APIs. New services should receive
 * ConfigRepositoryInterface (or one of the typed domain configs) directly.
 */
final class Config
{
    private static ?ConfigRepositoryInterface $repository = null;

    public static function repository(): ConfigRepositoryInterface
    {
        if (self::$repository !== null) {
            return self::$repository;
        }

        $repository = ConfigRepository::fromRoot();

        // Serviços recebem uma instância pelo container e, portanto, sempre
        // mantêm um snapshot de bootstrap. Esta fachada existe só para APIs
        // estáticas legadas; sem cache ela não deve congelar superglobais de
        // uma aplicação embutida ou de uma suíte de testes.
        if (is_file($repository->cacheFile())) {
            self::$repository = $repository;
        }

        return $repository;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::repository()->get($key, $default);
    }

    public static function reset(): void
    {
        self::$repository = null;
    }

    /** @internal useful for embedding applications and tests. */
    public static function setRepository(?ConfigRepositoryInterface $repository): void
    {
        self::$repository = $repository;
    }
}
