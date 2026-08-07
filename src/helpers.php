<?php

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
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            'empty', '(empty)' => '',
            default            => $value,
        };
    }
}
