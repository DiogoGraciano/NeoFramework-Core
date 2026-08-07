<?php

namespace NeoFramework\Core\Middleware;

use NeoFramework\Core\Interfaces\Middleware;
use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Response;
use NeoFramework\Core\Url;

class SecurityHeaders implements Middleware
{
    private array $config;

    public function __construct(array $config = [])
    {
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

    public function before(Controller $controller): Controller
    {
        $response = $controller->getResponse();

        foreach ($this->config as $header => $value) {
            if (!$value) {
                continue;
            }

            // HSTS sobre HTTP é ignorado pelo navegador e só serve para
            // confundir quem inspeciona a resposta.
            if ($header === 'strict-transport-security' && !Url::isSecure()) {
                continue;
            }

            $response->addHeader($header, $value);
        }

        return $controller;
    }

    public function after(Response $response): Response
    {
        return $response;
    }

    public static function fromEnv(): self
    {
        $config = [];

        $xFrameOptions = env('X_FRAME_OPTIONS');
        if ($xFrameOptions) {
            $config['x-frame-options'] = $xFrameOptions;
        }

        $xContentOptions = env('X_CONTENT_OPTIONS');
        if ($xContentOptions) {
            $config['x-content-type-options'] = $xContentOptions;
        }

        $referrerPolicy = env('REFERRER_POLICY');
        if ($referrerPolicy) {
            $config['referrer-policy'] = $referrerPolicy;
        }

        $contentSecurityPolicy = env('CONTENT_SECURITY_POLICY');
        if ($contentSecurityPolicy) {
            $config['content-security-policy'] = $contentSecurityPolicy;
        }

        $permissionsPolicy = env('PERMISSIONS_POLICY');
        if ($permissionsPolicy) {
            $config['permissions-policy'] = $permissionsPolicy;
        }

        $strictTransportSecurity = env('STRICT_TRANSPORT_SECURITY');
        if ($strictTransportSecurity) {
            $config['strict-transport-security'] = $strictTransportSecurity;
        }

        return new self($config);
    }
}
