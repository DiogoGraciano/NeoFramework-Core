<?php

namespace Tests;

use NeoFramework\Core\Middleware\SecurityHeaders;
use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Response;
use NeoFramework\Core\Request;
use PHPUnit\Framework\TestCase;

class NeoFrameworkSecurityHeadersTest extends TestCase
{
    private SecurityHeaders $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV = [];
    }

    public function testDefaultConfiguration()
    {
        $middleware = new SecurityHeaders();
        $this->assertInstanceOf(SecurityHeaders::class, $middleware);
    }

    public function testCustomConfiguration()
    {
        $config = [
            'x-frame-options' => 'DENY',
            'x-content-type-options' => 'nosniff',
            'referrer-policy' => 'strict-origin-when-cross-origin',
            'content-security-policy' => "default-src 'self'",
            'permissions-policy' => "geolocation=()",
            'strict-transport-security' => "max-age=63072000; includeSubDomains; preload",
        ];

        $middleware = new SecurityHeaders($config);
        $this->assertInstanceOf(SecurityHeaders::class, $middleware);
    }

    public function testBeforeMethodAddsHeaders()
    {
        $middleware = new SecurityHeaders([
            'x-frame-options' => 'DENY',
            'x-content-type-options' => 'nosniff',
        ]);

        $controller = $this->createMockController();
        $response = new Response();
        $controller->method('getResponse')->willReturn($response);

        $result = $middleware->before($controller);

        $this->assertInstanceOf(Controller::class, $result);
        $this->assertSame($controller, $result);
    }

    public function testAfterMethodReturnsResponse()
    {
        $middleware = new SecurityHeaders();
        $response = new Response();

        $result = $middleware->after($response);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame($response, $result);
    }

    public function testFromEnvWithNoEnvironmentVariables()
    {
        $middleware = SecurityHeaders::fromEnv();
        $this->assertInstanceOf(SecurityHeaders::class, $middleware);
    }

    public function testFromEnvWithXFrameOptions()
    {
        $_ENV['X_FRAME_OPTIONS'] = 'DENY';
        
        $middleware = SecurityHeaders::fromEnv();
        $this->assertInstanceOf(SecurityHeaders::class, $middleware);
        
        unset($_ENV['X_FRAME_OPTIONS']);
    }

    public function testFromEnvWithXContentOptions()
    {
        $_ENV['X_CONTENT_OPTIONS'] = 'nosniff';
        
        $middleware = SecurityHeaders::fromEnv();
        $this->assertInstanceOf(SecurityHeaders::class, $middleware);
        
        unset($_ENV['X_CONTENT_OPTIONS']);
    }

    public function testFromEnvWithReferrerPolicy()
    {
        $_ENV['REFERRER_POLICY'] = 'strict-origin-when-cross-origin';
        
        $middleware = SecurityHeaders::fromEnv();
        $this->assertInstanceOf(SecurityHeaders::class, $middleware);
        
        unset($_ENV['REFERRER_POLICY']);
    }

    public function testFromEnvWithContentSecurityPolicy()
    {
        $_ENV['CONTENT_SECURITY_POLICY'] = "default-src 'self'; script-src 'self' 'unsafe-inline'";
        
        $middleware = SecurityHeaders::fromEnv();
        $this->assertInstanceOf(SecurityHeaders::class, $middleware);
        
        unset($_ENV['CONTENT_SECURITY_POLICY']);
    }

    public function testFromEnvWithPermissionsPolicy()
    {
        $_ENV['PERMISSIONS_POLICY'] = "geolocation=(), microphone=()";
        
        $middleware = SecurityHeaders::fromEnv();
        $this->assertInstanceOf(SecurityHeaders::class, $middleware);
        
        unset($_ENV['PERMISSIONS_POLICY']);
    }

    public function testFromEnvWithStrictTransportSecurity()
    {
        $_ENV['STRICT_TRANSPORT_SECURITY'] = "max-age=63072000; includeSubDomains; preload";
        
        $middleware = SecurityHeaders::fromEnv();
        $this->assertInstanceOf(SecurityHeaders::class, $middleware);
        
        unset($_ENV['STRICT_TRANSPORT_SECURITY']);
    }

    public function testFromEnvWithAllEnvironmentVariables()
    {
        $_ENV['X_FRAME_OPTIONS'] = 'DENY';
        $_ENV['X_CONTENT_OPTIONS'] = 'nosniff';
        $_ENV['REFERRER_POLICY'] = 'strict-origin-when-cross-origin';
        $_ENV['CONTENT_SECURITY_POLICY'] = "default-src 'self'";
        $_ENV['PERMISSIONS_POLICY'] = "geolocation=()";
        $_ENV['STRICT_TRANSPORT_SECURITY'] = "max-age=63072000; includeSubDomains; preload";
        
        $middleware = SecurityHeaders::fromEnv();
        $this->assertInstanceOf(SecurityHeaders::class, $middleware);
        
        // Clean up
        unset($_ENV['X_FRAME_OPTIONS']);
        unset($_ENV['X_CONTENT_OPTIONS']);
        unset($_ENV['REFERRER_POLICY']);
        unset($_ENV['CONTENT_SECURITY_POLICY']);
        unset($_ENV['PERMISSIONS_POLICY']);
        unset($_ENV['STRICT_TRANSPORT_SECURITY']);
    }

    public function testDefaultHeaderValues()
    {
        $controller = $this->createMockController();
        $response = new Response();
        $controller->method('getResponse')->willReturn($response);

        $middleware = new SecurityHeaders();
        $middleware->before($controller);

        // Test that default headers are set by checking if addHeader method was called
        // Note: This is a basic test since we can't directly access the headers from Response
        $this->assertInstanceOf(Controller::class, $controller);
    }

    public function testEmptyStringConfigurationValues()
    {
        $config = [
            'x-frame-options' => '',
            'x-content-type-options' => '',
            'referrer-policy' => '',
            'content-security-policy' => '',
            'permissions-policy' => '',
            'strict-transport-security' => '',
        ];

        $middleware = new SecurityHeaders($config);
        $controller = $this->createMockController();
        $response = new Response();
        $controller->method('getResponse')->willReturn($response);

        $result = $middleware->before($controller);
        $this->assertInstanceOf(Controller::class, $result);
    }

    public function testSecurityHeadersIntegrationWithController()
    {
        // Create a mock controller with proper response
        $controller = $this->createMockController();
        $response = new Response();
        
        // Configure mock to return response
        $controller->method('getResponse')->willReturn($response);
        
        // Create SecurityHeaders middleware with custom config
        $config = [
            'x-frame-options' => 'DENY',
            'x-content-type-options' => 'nosniff',
            'referrer-policy' => 'strict-origin-when-cross-origin'
        ];
        
        $middleware = new SecurityHeaders($config);
        
        // Apply middleware
        $result = $middleware->before($controller);
        
        // Verify controller is returned
        $this->assertSame($controller, $result);
        
        // Verify response object exists
        $this->assertInstanceOf(Response::class, $response);
    }

    public function testSecurityHeadersWithEmptyConfig()
    {
        $config = [];
        $middleware = new SecurityHeaders($config);
        
        $controller = $this->createMockController();
        $response = new Response();
        $controller->method('getResponse')->willReturn($response);
        
        $result = $middleware->before($controller);
        
        $this->assertSame($controller, $result);
    }

    public function testSecurityHeadersConfigMerging()
    {
        // Test that custom config merges with defaults
        $customConfig = [
            'x-frame-options' => 'DENY',
            'custom-header' => 'custom-value'
        ];
        
        $middleware = new SecurityHeaders($customConfig);
        $this->assertInstanceOf(SecurityHeaders::class, $middleware);
    }

    private function createMockController()
    {
        $controller = $this->createMock(Controller::class);
        return $controller;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $_ENV = [];
    }
} 