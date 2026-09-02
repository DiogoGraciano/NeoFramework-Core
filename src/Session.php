<?php
declare(strict_types=1);

namespace NeoFramework\Core;

use NeoFramework\Core\Config\SessionConfig;
use NeoFramework\Core\Http\RequestScopeContext;

final class Session
{
    private const CSRF_KEY = "CSRF_TOKEN";

    /**
     * @param int|string|null $cacheExpire Minutos. Aceita string por
     *        compatibilidade, mas session_cache_expire() exige int — sob
     *        strict_types a coerção silenciosa vira TypeError.
     */
    public static function start(int|string|null $cacheExpire = null, ?string $cacheLimiter = null):void
    {
        if (session_status() === PHP_SESSION_NONE) {

            if ($cacheLimiter !== null) {
                session_cache_limiter($cacheLimiter);
            }

            if ($cacheExpire !== null) {
                session_cache_expire((int) $cacheExpire);
            }

            $config = SessionConfig::from(Config::repository());
            session_set_cookie_params(['httponly' => $config->httpOnly, 'secure' => $config->secure ?? Url::isSecure(), 'samesite' => $config->sameSite]);

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
                    'expires' => time() - 42000,
                    'path' => $params["path"],
                    'domain' => $params["domain"],
                    'secure' => $params["secure"],
                    'httponly' => $params["httponly"],
                    'samesite' => $params["samesite"] ?: 'Lax',
                ]
            );
        }

        return session_destroy();
    }

    public static function set(string $nome, $valor):void
    {
        $scope = RequestScopeContext::current();
        if ($scope !== null) {
            $data = $scope->get('neoframework.session', []);
            $data["neof_" . $nome] = $valor;
            $scope->set('neoframework.session', $data);
            return;
        }
        // O array é criado se ainda não existir: em CLI (jobs, comandos) não há
        // sessão ativa, e a versão anterior emitia warning ao escrever.
        if (!isset($_SESSION)) {
            $_SESSION = [];
        }

        $_SESSION["neof_" . $nome] = $valor;
    }

    public static function get(string $nome):mixed
    {
        $scope = RequestScopeContext::current();
        if ($scope !== null) {
            $data = $scope->get('neoframework.session', []);
            return is_array($data) ? ($data["neof_" . $nome] ?? null) : null;
        }
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            return null;
        }

        return array_key_exists("neof_" . $nome, $_SESSION) ? $_SESSION["neof_" . $nome] : null;
    }

    public static function remove(string $nome): void
    {
        $scope = RequestScopeContext::current();
        if ($scope !== null) {
            $data = $scope->get('neoframework.session', []);
            if (is_array($data)) unset($data['neof_' . $nome]);
            $scope->set('neoframework.session', $data);
            return;
        }
        unset($_SESSION['neof_' . $nome]);
    }

    /** Invalida todos os dados da sessão e troca seu identificador, se houver um. */
    public static function invalidate(): void
    {
        $scope = RequestScopeContext::current();
        if ($scope !== null) $scope->set('neoframework.session', []);
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_regenerate_id(true);
            return;
        }
        $_SESSION = [];
    }
}
