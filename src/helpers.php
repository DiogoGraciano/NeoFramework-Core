<?php

if (!function_exists('env')) {
    function env(string $key): string
    {
        $key = strtoupper($key);
        return isset($_ENV[$key]) ? $_ENV[$key] : "";
    }
}