<?php
declare(strict_types=1);
if (!function_exists('env')) {
    /**
     * Lê uma variável de ambiente, convertendo os literais textuais que o
     * .env não sabe tipar.
     *
     * "true"/"false"/"null"/"(true)"/"empty" viram os valores correspondentes,
     * de modo que env('CORS_ENABLED') possa ser usado direto num if em vez de
     * comparado com a string "true".
     */
    function env(string $key, mixed $default = null): mixed
    {
        $key = strtoupper($key);

        if (array_key_exists($key, $_ENV)) {
            $value = $_ENV[$key];
        } elseif (array_key_exists($key, $_SERVER)) {
            $value = $_SERVER[$key];
        } else {
            return $default;
        }

        if (!is_string($value)) {
            return $value;
        }

        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}

if (!function_exists('route')) {
    /** Generate a path from a named route. */
    function route(string $name, array $parameters = [], array $query = []): string
    {
        return \NeoFramework\Core\Routing\UrlGeneratorFactory::make()->path($name, $parameters, $query);
    }
}

if (!function_exists('route_url')) {
    /** Generate an absolute URL from a named route. Requires app.url. */
    function route_url(string $name, array $parameters = [], array $query = []): string
    {
        return \NeoFramework\Core\Routing\UrlGeneratorFactory::make()->url($name, $parameters, $query);
    }
}

if (!function_exists('signed_route')) {
    /** Generate an HMAC-signed path from a named route. */
    function signed_route(string $name, array $parameters = [], array $query = [], ?int $expiresInSeconds = null): string
    {
        return \NeoFramework\Core\Routing\UrlGeneratorFactory::signer()->sign($name, $parameters, $query, $expiresInSeconds);
    }
}

if (!function_exists('config')) {
    /** Read a bootstrap configuration value using dot notation. */
    function config(string $key, mixed $default = null): mixed
    {
        return \NeoFramework\Core\Config::get($key, $default);
    }
}
