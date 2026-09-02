<?php
declare(strict_types=1);

namespace NeoFramework\Core\Middleware;

use NeoFramework\Core\Config;
use NeoFramework\Core\Config\SecurityHeadersConfig;
use NeoFramework\Core\Url;
use NeoFramework\Core\Vite;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class SecurityHeaders implements MiddlewareInterface
{
    private array $config;

    private bool $viteCspRelax;

    public function __construct(array $config = [], bool $viteCspRelax = true)
    {
        $this->viteCspRelax = $viteCspRelax;
        $this->config = array_merge([
            'x-frame-options' => 'SAMEORIGIN',
            'x-content-type-options' => 'nosniff',
            'referrer-policy' => 'strict-origin-when-cross-origin',
            // Sem 'unsafe-inline'/'unsafe-eval': com eles a política não impede
            // a execução de script injetado, que é o motivo de existir uma CSP.
            // Quem precisar de script inline deve usar nonce ou hash.
            'content-security-policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; media-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'",
            'permissions-policy' => "geolocation=(),microphone=(),camera=()",
            'strict-transport-security' => "max-age=31536000; includeSubDomains",
        ], $config);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        foreach ($this->config as $header => $value) {
            if (!$value) {
                continue;
            }

            // HSTS sobre HTTP é ignorado pelo navegador e só serve para
            // confundir quem inspeciona a resposta.
            if ($header === 'strict-transport-security' && !Url::isSecure()) {
                continue;
            }

            if ($header === 'content-security-policy') {
                $value = $this->relaxForVite($value);
            }

            $response = $response->withHeader($header, $value);
        }

        return $response;
    }

    /**
     * Abre a CSP para o dev server do Vite — e só para ele.
     *
     * A condição inteira mora em Vite::cspSources(), que devolve vazio a menos
     * que ENVIRONMENT != "prod" E exista um arquivo `hot` com uma origem válida.
     * As duas condições juntas garantem que nem um ENVIRONMENT mal configurado
     * nem um `hot` deixado para trás por um dev server morto afrouxem a política
     * sozinhos. Em produção o header sai byte a byte igual ao de sempre.
     */
    private function relaxForVite(string $policy): string
    {
        if (!$this->viteCspRelax) {
            return $policy;
        }

        $additions = Vite::instance()->cspSources();

        return $additions === [] ? $policy : self::mergeCspSources($policy, $additions);
    }

    /**
     * Acrescenta fontes a diretivas já existentes.
     *
     * Não basta concatenar "; script-src http://…": uma diretiva repetida na
     * mesma política é ignorada — vale a primeira ocorrência —, então o append
     * silencioso não teria efeito nenhum e o HMR continuaria bloqueado.
     *
     * Uma diretiva ausente da política herda de default-src antes de receber a
     * origem nova, para não transformar uma restrição implícita em permissão
     * ampla.
     *
     * @param array<string,list<string>> $additions
     */
    private static function mergeCspSources(string $policy, array $additions): string
    {
        $directives = [];
        $order = [];

        foreach (explode(';', $policy) as $chunk) {
            $chunk = trim($chunk);

            if ($chunk === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $chunk) ?: [];
            $name = strtolower((string) array_shift($parts));

            if (!isset($directives[$name])) {
                $order[] = $name;
            }

            $directives[$name] = $parts;
        }

        foreach ($additions as $name => $sources) {
            if (!array_key_exists($name, $directives)) {
                $directives[$name] = $directives['default-src'] ?? ["'self'"];
                $order[] = $name;
            }

            // 'none' precisa ser a única fonte da diretiva; ao acrescentar
            // qualquer origem ele deixa de fazer sentido e é descartado.
            $directives[$name] = array_values(array_filter(
                $directives[$name],
                static fn (string $source): bool => $source !== "'none'"
            ));

            foreach ($sources as $source) {
                if (!in_array($source, $directives[$name], true)) {
                    $directives[$name][] = $source;
                }
            }
        }

        $out = [];

        foreach ($order as $name) {
            $out[] = trim($name . ' ' . implode(' ', $directives[$name]));
        }

        return implode('; ', $out);
    }

    /** @deprecated Configuration is loaded during bootstrap; use fromConfig(). */
    public static function fromEnv(): self
    {
        return self::fromConfig(SecurityHeadersConfig::from(Config::repository()));
    }

    public static function fromConfig(SecurityHeadersConfig $config): self
    {
        return new self($config->headers, $config->viteCspRelax);
    }

}
