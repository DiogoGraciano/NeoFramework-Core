<?php
declare(strict_types=1);

namespace NeoFramework\Core\Middleware;

use NeoFramework\Core\Config;
use NeoFramework\Core\Config\CorsConfig;
use NeoFramework\Core\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class Cors implements MiddlewareInterface
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'allowed_origins' => ['*'],
            'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH'],
            'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept', 'Origin'],
            'exposed_headers' => [],
            'max_age' => 86400, // 24 hours
            'allow_credentials' => false,
        ], $config);

        // Refletir a Origin do requisitante junto de Allow-Credentials permite
        // que qualquer site leia respostas autenticadas. O próprio padrão CORS
        // proíbe a combinação; falhar aqui evita o falso senso de proteção.
        if ($this->config['allow_credentials'] && in_array('*', $this->config['allowed_origins'], true)) {
            throw new \InvalidArgumentException(
                "CORS: allow_credentials não pode ser usado com allowed_origins '*'. " .
                "Liste explicitamente as origens em CORS_ORIGINS."
            );
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = strtoupper($request->getMethod()) === 'OPTIONS' ? new Response(204) : $handler->handle($request);
        return $this->addCorsHeaders($response, $request);
    }

    private function addCorsHeaders(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin') ?: null;

        // Sem Origin não é uma requisição cross-origin; não há o que liberar.
        if ($origin && $this->isOriginAllowed($origin)) {
            if (in_array('*', $this->config['allowed_origins'], true)) {
                $response = $response->withHeader('Access-Control-Allow-Origin', '*');
            } else {
                // Só chega aqui quando a origin consta da allowlist.
                $response = $response->withHeader('Access-Control-Allow-Origin', $origin);
            }
        }

        $response = $response->withHeader('Access-Control-Allow-Methods', implode(', ', $this->config['allowed_methods']));

        $response = $response->withHeader('Access-Control-Allow-Headers', implode(', ', $this->config['allowed_headers']));

        if (!empty($this->config['exposed_headers'])) {
            $response = $response->withHeader('Access-Control-Expose-Headers', implode(', ', $this->config['exposed_headers']));
        }

        $response = $response->withHeader('Access-Control-Max-Age', (string) $this->config['max_age']);

        if ($this->config['allow_credentials']) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response->withAddedHeader('Vary', 'Origin');
    }

    private function isOriginAllowed(?string $origin): bool
    {
        if (!$origin) {
            return false;
        }

        if (in_array('*', $this->config['allowed_origins'], true)) {
            return true;
        }

        return in_array($origin, $this->config['allowed_origins'], true);
    }

    /** @deprecated Configuration is loaded during bootstrap; use fromConfig(). */
    public static function fromEnv(): self
    {
        return self::fromConfig(CorsConfig::from(Config::repository()));
    }

    public static function fromConfig(CorsConfig $config): self
    {
        return new self($config->middleware());
    }
}
