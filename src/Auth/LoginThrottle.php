<?php

declare(strict_types=1);

namespace NeoFramework\Core\Auth;

use InvalidArgumentException;
use NeoFramework\Core\Events\Events;
use NeoFramework\Core\Events\LoginFailed;
use NeoFramework\Core\Events\LoginThrottled;
use NeoFramework\Core\Exceptions\TooManyRequestsException;
use NeoFramework\Core\RateLimit\ClockInterface;
use NeoFramework\Core\RateLimit\RateLimitKey;
use NeoFramework\Core\RateLimit\RateLimitStoreInterface;
use NeoFramework\Core\RateLimit\SystemClock;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Bloqueio de força bruta na verificação de credencial.
 *
 * É deliberadamente uma camada distinta de `#[RateLimit]`. O middleware de rota
 * conta requisições; este conta **falhas**, e conta em duas dimensões:
 *
 * - por identificador, porque um atacante distribuído por muitos IPs varre uma
 *   conta sem nunca esgotar o contador de nenhum deles;
 * - por IP, porque um único host varrendo muitas contas não esgota o contador de
 *   conta nenhuma.
 *
 * Limitar só a rota falha nos dois casos, e ainda cobra o orçamento de quem
 * acertou a senha — um escritório atrás de um NAT derrubaria o próprio login.
 * Aqui o sucesso zera os contadores.
 */
final readonly class LoginThrottle
{
    public function __construct(
        private RateLimitStoreInterface $store,
        private int $limit = 5,
        private int $window = 900,
        private ClockInterface $clock = new SystemClock(),
    ) {
        if ($limit < 1 || $window < 1) throw new InvalidArgumentException('Login throttle limit and window must be positive.');
    }

    /**
     * Executa `$verify` sob o bloqueio e devolve a identidade, ou `null` se a
     * credencial não conferiu.
     *
     * A verificação é passada como callable de propósito: checar, verificar,
     * contar a falha e zerar no sucesso é uma sequência que não pode ser
     * cumprida pela metade. Expor os passos soltos deixaria a aplicação
     * esquecer justamente o `recordFailure`, que é o que dá segurança ao resto.
     *
     * @param callable():?IdentityInterface $verify
     *
     * @throws TooManyRequestsException quando o identificador ou o IP já estourou.
     */
    public function attempt(string $identifier, ServerRequestInterface $request, callable $verify): ?IdentityInterface
    {
        $ip = RateLimitKey::clientIp($request);
        $resetAt = $this->resetAt();
        $keys = $this->keys($identifier, $ip, $resetAt);

        foreach ($keys as $dimension => $key) {
            if ($this->store->read($key) < $this->limit) continue;

            $retryAfter = max(1, $resetAt - $this->clock->now());
            Events::dispatch(new LoginThrottled($identifier, $ip, $dimension, $retryAfter));

            throw new TooManyRequestsException(['Retry-After' => (string) $retryAfter]);
        }

        $identity = $verify();

        if ($identity === null) {
            foreach ($keys as $key) $this->store->increment($key, $resetAt);
            Events::dispatch(new LoginFailed($identifier, $ip));

            return null;
        }

        // Quem provou a credencial não deve carregar as tentativas anteriores:
        // sem isso, errar quatro vezes antes de acertar deixaria o próximo login
        // legítimo a uma falha do bloqueio.
        foreach ($keys as $key) $this->store->forget($key);

        return $identity;
    }

    /**
     * Chaves das duas dimensões.
     *
     * O identificador é normalizado e reduzido a hash: `Foo@Bar.com` e
     * `foo@bar.com` são a mesma conta e precisam do mesmo contador, e o e-mail
     * cru não tem por que ficar legível numa chave do Redis.
     *
     * @return array{identifier:string,ip:string}
     */
    private function keys(string $identifier, string $ip, int $resetAt): array
    {
        $normalized = hash('sha256', mb_strtolower(trim($identifier)));

        return [
            'identifier' => "login:id:{$normalized}:{$resetAt}",
            'ip' => "login:ip:{$ip}:{$resetAt}",
        ];
    }

    /** Mesma janela fixa do `FixedWindowRateLimiter`: o timestamp entra na chave. */
    private function resetAt(): int
    {
        return (intdiv($this->clock->now(), $this->window) + 1) * $this->window;
    }
}
