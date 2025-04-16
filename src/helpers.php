<?php

if (!function_exists('env')) {
    function env(string $key): string
    {
        $key = strtoupper($key);
        if(isset($_ENV[$key]))
            return $_ENV[$key];
        elseif(isset($_SERVER[$key]))
            return $_SERVER[$key];
        
        return "";
    }
}