<?php

namespace NeoFramework\Core\Middleware;

use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Interfaces\Middleware;
use NeoFramework\Core\Response;
use NeoFramework\Core\Request;

class Cors implements Middleware
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
    }

    public function before(Controller $controller): Controller
    {
        $request = $controller->getRequest();
        $response = $controller->getResponse();

        // Handle preflight OPTIONS request
        if ($request->server('REQUEST_METHOD') === 'OPTIONS') {
            $this->addCorsHeaders($response, $request);
            $response->setCode(200)->send();
        }

        // Add CORS headers for all requests
        $this->addCorsHeaders($response, $request);

        return $controller;
    }

    public function after(Response $response): Response
    {
        // Headers are already added in before method
        return $response;
    }

    private function addCorsHeaders(Response $response, Request $request): void
    {
        $origin = $request->getHeader('Origin');

        // Set Access-Control-Allow-Origin
        if ($this->isOriginAllowed($origin)) {
            if (in_array('*', $this->config['allowed_origins']) && !$this->config['allow_credentials']) {
                $response->addHeader('Access-Control-Allow-Origin', '*');
            } else {
                $response->addHeader('Access-Control-Allow-Origin', $origin ?: '*');
            }
        }

        // Set Access-Control-Allow-Methods
        $response->addHeader('Access-Control-Allow-Methods', implode(', ', $this->config['allowed_methods']));

        // Set Access-Control-Allow-Headers
        $response->addHeader('Access-Control-Allow-Headers', implode(', ', $this->config['allowed_headers']));

        // Set Access-Control-Expose-Headers
        if (!empty($this->config['exposed_headers'])) {
            $response->addHeader('Access-Control-Expose-Headers', implode(', ', $this->config['exposed_headers']));
        }

        // Set Access-Control-Max-Age
        $response->addHeader('Access-Control-Max-Age', (string) $this->config['max_age']);

        // Set Access-Control-Allow-Credentials
        if ($this->config['allow_credentials']) {
            $response->addHeader('Access-Control-Allow-Credentials', 'true');
        }

        // Add Vary header for proper caching
        $response->addHeader('Vary', 'Origin');
    }

    private function isOriginAllowed(?string $origin): bool
    {
        if (!$origin) {
            return true;
        }

        if (in_array('*', $this->config['allowed_origins'])) {
            return true;
        }

        return in_array($origin, $this->config['allowed_origins']);
    }

    /**
     * Create CORS middleware with environment-based configuration
     */
    public static function fromEnv(): self
    {
        $config = [];

        // Get allowed origins from environment
        $corsOrigins = env('CORS_ORIGINS','*');
        if ($corsOrigins !== '*') {
            $config['allowed_origins'] = array_map('trim', explode(',', $corsOrigins));
        }

        // Get allowed methods from environment
        $corsMethods = env('CORS_METHODS');   
        if ($corsMethods) {
            $config['allowed_methods'] = array_map('trim', explode(',', $corsMethods));
        }

        // Get allowed headers from environment
        $corsHeaders = env('CORS_HEADERS');
        if ($corsHeaders) {
            $config['allowed_headers'] = array_map('trim', explode(',', $corsHeaders));
        }

        // Get max age from environment
        $corsMaxAge = env('CORS_MAX_AGE');
        if ($corsMaxAge) {
            $config['max_age'] = (int) $corsMaxAge;
        }

        // Get credentials setting from environment
        $corsCredentials = env('CORS_CREDENTIALS');
        if ($corsCredentials !== null) {
            $config['allow_credentials'] = filter_var($corsCredentials, FILTER_VALIDATE_BOOLEAN);
        }

        return new self($config);
    }
} 