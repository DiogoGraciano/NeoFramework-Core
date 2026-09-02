<?php
declare(strict_types=1);

namespace NeoFramework\Core\Http;

use DateTimeInterface;
use InvalidArgumentException;

/** Um cookie como valor, com os atributos de segurança validados na construção. */
final readonly class Cookie
{
    private const SAME_SITE = ['Lax', 'Strict', 'None'];

    public function __construct(
        public string $name,
        public string $value = '',
        public ?int $expires = null,
        public string $path = '/',
        public ?string $domain = null,
        public bool $secure = false,
        public bool $httpOnly = true,
        public string $sameSite = 'Lax',
    ) {
        if ($name === '' || preg_match('/[=,; \t\r\n\013\014]/', $name) === 1) throw new InvalidArgumentException('O nome do cookie contém caracteres inválidos.');
        if (!in_array($sameSite, self::SAME_SITE, true)) throw new InvalidArgumentException('SameSite deve ser Lax, Strict ou None.');
        // O navegador descarta silenciosamente SameSite=None sem Secure; falhar
        // aqui evita a sessão que "some" só em produção atrás de HTTPS.
        if ($sameSite === 'None' && !$secure) throw new InvalidArgumentException('SameSite=None exige Secure.');
    }

    public static function expiring(string $name, string $path = '/', ?string $domain = null, bool $secure = false): self
    {
        return new self($name, '', 1, $path, $domain, $secure);
    }

    public function withExpiration(string|int|DateTimeInterface|null $expires): self
    {
        $timestamp = match (true) {
            $expires === null => null,
            $expires instanceof DateTimeInterface => $expires->getTimestamp(),
            is_int($expires) => $expires,
            default => strtotime($expires) ?: throw new InvalidArgumentException("Não foi possível interpretar a expiração \"{$expires}\"."),
        };

        return new self($this->name, $this->value, $timestamp, $this->path, $this->domain, $this->secure, $this->httpOnly, $this->sameSite);
    }

    public function toHeader(): string
    {
        $parts = [$this->name . '=' . rawurlencode($this->value)];

        if ($this->expires !== null) {
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s T', $this->expires);
            $parts[] = 'Max-Age=' . max(0, $this->expires - time());
        }

        $parts[] = 'Path=' . $this->path;
        if ($this->domain !== null) $parts[] = 'Domain=' . $this->domain;
        if ($this->secure) $parts[] = 'Secure';
        if ($this->httpOnly) $parts[] = 'HttpOnly';
        $parts[] = 'SameSite=' . $this->sameSite;

        return implode('; ', $parts);
    }
}
