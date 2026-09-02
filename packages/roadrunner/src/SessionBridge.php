<?php

declare(strict_types=1);

namespace NeoFramework\RoadRunner;

use NeoFramework\Core\Config;
use NeoFramework\Core\Config\SessionConfig;
use NeoFramework\Core\Http\Cookie;
use NeoFramework\Core\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Liga a sessão nativa do PHP ao ciclo PSR-7 do RoadRunner.
 *
 * Sob FPM e FrankenPHP o PHP recompõe `$_COOKIE` e coleta o `Set-Cookie` que
 * `session_start()` emite via `header()`. O RoadRunner não faz nem uma coisa nem
 * outra: ele entrega um request PSR-7 e monta a resposta a partir do objeto PSR-7,
 * ignorando `header()`. Sem esta ponte a sessão simplesmente não existia no
 * RoadRunner — nenhum cookie saía, e todo request começava do zero.
 */
final class SessionBridge
{
    /** O formato que o PHP gera. Aceitar id arbitrário do cliente é vetor de fixação. */
    private const ID = '/^[A-Za-z0-9,-]{22,256}$/';

    private bool $hadIncomingId = false;

    public function open(ServerRequestInterface $request): void
    {
        $cookies = [];
        foreach ($request->getCookieParams() as $name => $value) {
            if (is_string($name) && is_scalar($value)) $cookies[$name] = (string) $value;
        }

        $_COOKIE = $cookies;

        $incoming = $cookies[session_name()] ?? null;
        $this->hadIncomingId = $incoming !== null && preg_match(self::ID, $incoming) === 1;
        if ($this->hadIncomingId) session_id((string) $incoming);

        Session::start();
    }

    public function close(ResponseInterface $response): ResponseInterface
    {
        if (session_status() !== PHP_SESSION_ACTIVE) return $response;

        $id = session_id();
        $emit = !$this->hadIncomingId || ($_COOKIE[session_name()] ?? null) !== $id;
        $name = session_name();

        session_write_close();

        // Zerar é o ponto crítico: sem isto o worker mantém o id do cliente
        // anterior e a próxima requisição, de outra pessoa, abre a sessão dele.
        $_SESSION = [];
        $_COOKIE = [];
        session_id('');
        $this->hadIncomingId = false;

        if (!$emit || $id === false || $id === '') return $response;

        $config = SessionConfig::from(Config::repository());
        $cookie = new Cookie(
            (string) $name,
            $id,
            secure: $config->secure ?? false,
            httpOnly: $config->httpOnly,
            sameSite: $config->sameSite,
        );

        return $response->withAddedHeader('Set-Cookie', $cookie->toHeader());
    }
}
