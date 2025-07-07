<?php

namespace NeoFramework\Core\Middleware;

use NeoFramework\Core\Interfaces\Middleware;
use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Response;

class SecurityHeaders implements Middleware
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'x-frame-options' => 'SAMEORIGIN',
            'x-content-type-options' => 'nosniff',
            'referrer-policy' => 'no-referrer-when-downgrade',
            'content-security-policy' => "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src * data:; font-src *; connect-src 'self'; media-src *;",
            'permissions-policy' => "geolocation=(),microphone=(),camera=()",
            'strict-transport-security' => "max-age=31536000; includeSubDomains",
        ], $config);
    }

    public function before(Controller $controller): Controller
    {
        $response = $controller->getResponse();

        foreach ($this->config as $header => $value) {
            if ($value) {
                $response->addHeader($header, $value);
            }
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
