<?php

namespace NeoFramework\Core;

final class Session
{
    private const CSRF_KEY = "CSRF_TOKEN";

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
                'httponly' => true,
                'secure'   => Url::isSecure(),
                'samesite' => env('SESSION_SAMESITE', 'Lax'),
            ]);

            session_start();

            self::ensureCsrfToken();
        }
    }

    /**
     * Gera o token CSRF apenas na primeira vez.
     *
     * Regenerar a cada requisição invalidaria todo formulário já renderizado,
     * tornando a validação impossível de passar.
     */
    private static function ensureCsrfToken():void
    {
        if (self::get(self::CSRF_KEY) === null) {
            self::set(self::CSRF_KEY, bin2hex(random_bytes(32)));
        }
    }

    /**
     * Força a criação de um token novo. Use após login/logout.
     */
    public static function regenerateCsrfToken():string
    {
        $token = bin2hex(random_bytes(32));
        self::set(self::CSRF_KEY, $token);

        return $token;
    }

    public static function getCsrfToken():?string
    {
        return self::get(self::CSRF_KEY);
    }

    /**
     * Compara em tempo constante o token recebido com o da sessão.
     */
    public static function validateCsrfToken(?string $token):bool
    {
        $expected = self::getCsrfToken();

        if (!is_string($expected) || $expected === "" || !is_string($token) || $token === "") {
            return false;
        }

        return hash_equals($expected, $token);
    }

    /**
     * Troca o identificador da sessão preservando os dados.
     *
     * Deve ser chamado sempre que o nível de privilégio mudar (login), para
     * impedir fixação de sessão.
     */
    public static function regenerateId(bool $deleteOldSession = true):bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        return session_regenerate_id($deleteOldSession);
    }

    public static function getId():string
    {
        return \session_id();
    }

    /**
     * Encerra a sessão: limpa os dados, expira o cookie e destrói o registro.
     */
    public static function destroy():bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        $_SESSION = [];

        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires'  => time() - 42000,
                    'path'     => $params["path"],
                    'domain'   => $params["domain"],
                    'secure'   => $params["secure"],
                    'httponly' => $params["httponly"],
                    'samesite' => $params["samesite"] ?: 'Lax',
                ]
            );
        }

        return session_destroy();
    }

    public static function set(string $nome, $valor):void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION["neof_".$nome] = $valor;
    }

    public static function get(string $nome):mixed
    {
        if (session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION)) {
            return null;
        }

        return array_key_exists("neof_".$nome, $_SESSION) ? $_SESSION["neof_".$nome] : null;
    }
}
