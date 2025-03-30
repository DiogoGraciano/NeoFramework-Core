<?php

namespace NeoFramework\Core;

final class Session
{
    public static function start(?string $cacheExpire = null, ?string $cacheLimiter = null):void
    {
        if (session_status() === PHP_SESSION_NONE) {

            if ($cacheLimiter !== null) {
                session_cache_limiter($cacheLimiter);
            }

            if ($cacheExpire !== null) {
                session_cache_expire($cacheExpire);
            }

            session_set_cookie_params([
                'httponly' => true
            ]);

            session_start();

            self::generateCsrfToken();
        }
    }

    private static function generateCsrfToken():void
    {
        self::set("CSRF_TOKEN",md5(uniqid(rand(), true)));
    }

    public static function getCsrfToken():string
    {
        return self::get("CSRF_TOKEN");
    }

    public static function getId():string
    {
        return \session_id();
    }

    public static function destroy():bool
    {
        return session_destroy();
    }

    public static function set(string $nome, $valor):void
    {
        $_SESSION["neof_".$nome] = $valor;
    }

    public static function get(string $nome):mixed
    {
        return array_key_exists("neof_".$nome, $_SESSION) ? $_SESSION["neof_".$nome] : null;
    }
}