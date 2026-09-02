<?php

declare(strict_types=1);

namespace NeoFramework\Core\Routing;

use NeoFramework\Core\Config;
use NeoFramework\Core\Config\AppConfig;
use NeoFramework\Core\Config\CryptoConfig;
use NeoFramework\Core\Http\BasePath;
use NeoFramework\Core\RouteCache;
use RuntimeException;

/** Monta os geradores de URL a partir da configuração e do mapa de rotas. */
final class UrlGeneratorFactory
{
    private function __construct()
    {
    }

    public static function make(): UrlGenerator
    {
        return new UrlGenerator(
            RouteCache::get()['named'],
            BasePath::resolve($_SERVER),
            AppConfig::from(Config::repository())->url,
        );
    }

    /**
     * A chave de assinatura é a de autenticação do `crypto`, não a de
     * criptografia: são propósitos distintos, e reusar a mesma chave para
     * assinar e cifrar enfraquece as duas.
     */
    public static function signer(): SignedUrlGenerator
    {
        $key = CryptoConfig::from(Config::repository())->authenticationKey;
        if ($key === '') throw new RuntimeException("Assinar URLs exige 'crypto.authentication_key' configurada.");

        return new SignedUrlGenerator(self::make(), $key);
    }
}
