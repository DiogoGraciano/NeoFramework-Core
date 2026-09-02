<?php
declare(strict_types=1);

namespace NeoFramework\Core\RateLimit;

use NeoFramework\Core\Auth\AuthContext;
use Psr\Http\Message\ServerRequestInterface;

enum RateLimitKey: string
{
    case Ip = 'ip';
    case User = 'user';
    case UserOrIp = 'user_or_ip';

    /**
     * `REMOTE_ADDR` e nada mais. `X-Forwarded-For` é escrito pelo cliente: aceitá-lo
     * sem validar o proxy deixaria qualquer atacante trocar de "IP" a cada tentativa
     * e zerar o próprio contador.
     */
    public static function clientIp(ServerRequestInterface $request): string
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';

        return is_string($ip) && $ip !== '' ? $ip : 'unknown';
    }

    public function valueFor(ServerRequestInterface $request): string
    {
        $user = AuthContext::current()->identity?->id();
        $ip = self::clientIp($request);

        return match ($this) {
            self::Ip => 'ip:' . $ip,
            self::User => 'user:' . ($user ?? 'anonymous'),
            self::UserOrIp => $user === null ? 'ip:' . $ip : 'user:' . $user,
        };
    }
}
