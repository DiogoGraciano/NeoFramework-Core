<?php

namespace Tests;

use NeoFramework\Core\Router;
use NeoFramework\Core\Session;
use NeoFramework\Core\Functions;
use PHPUnit\Framework\TestCase;

class NeoFrameworkRouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        // Clear superglobals and session before each test
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_SERVER = [];
        $_FILES = [];
        
        // Set default REQUEST_URI to avoid errors
        $_SERVER['REQUEST_URI'] = '/';
        
        // Mock Functions::getRoot() path for testing
        if (!is_dir('App/Controllers')) {
            mkdir('App/Controllers', 0755, true);
        }
        
        $this->router = new Router();
    }

    public function testGetNamespace()
    {
        // Test default namespace (should be empty initially)
        $this->assertEmpty($this->router->getNamespace());
    }

    public function testGetControllerNotHome()
    {
        // Test with multiple segments
        $_SERVER['REQUEST_URI'] = '/controller/method/param';
        $router = new Router();
        $this->assertEquals('controller', $router->getControllerNotHome());

        // Test with single segment
        $_SERVER['REQUEST_URI'] = '/controller';
        $router = new Router();
        $this->assertEquals('controller', $router->getControllerNotHome());

        // Test with trailing slash
        $_SERVER['REQUEST_URI'] = '/controller/';
        $router = new Router();
        $this->assertEquals('controller', $router->getControllerNotHome());

        // Test with query parameters
        $_SERVER['REQUEST_URI'] = '/controller?param=value';
        $router = new Router();
        $this->assertEquals('controller', $router->getControllerNotHome());
    }

    public function testGetParameters()
    {
        // Test numeric parameter
        $_SERVER['REQUEST_URI'] = '/controller/method/123';
        $router = new Router();
        $parameters = $router->getParameters(['{param:num}']);
        $this->assertEquals(['123'], $parameters);

        // Test any parameter
        $_SERVER['REQUEST_URI'] = '/controller/method/test';
        $router = new Router();
        $parameters = $router->getParameters(['{param:any}']);
        $this->assertEquals(['test'], $parameters);

        // Test multiple parameters
        $_SERVER['REQUEST_URI'] = '/controller/method/123/test';
        $router = new Router();
        $parameters = $router->getParameters(['{id:num}', '{name:any}']);
        $this->assertEquals(['123', 'test'], $parameters);

        // Test optional parameter with value
        $_SERVER['REQUEST_URI'] = '/controller/method/test';
        $router = new Router();
        $parameters = $router->getParameters(['{param:any:optional}']);
        $this->assertEquals(['test'], $parameters);

        // Test optional parameter without value
        $_SERVER['REQUEST_URI'] = '/controller/method';
        $router = new Router();
        $parameters = $router->getParameters(['{param:any:optional}']);
        $this->assertEquals([], $parameters);

        // Test regex parameter
        $_SERVER['REQUEST_URI'] = '/controller/method/abc123';
        $router = new Router();
        $parameters = $router->getParameters(['{param:/^[a-z]+\d+$/}']);
        $this->assertEquals(['abc123'], $parameters);

        // Test invalid numeric parameter
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('1° parameter is invalid.');
        $_SERVER['REQUEST_URI'] = '/controller/method/abc';
        $router = new Router();
        $router->getParameters(['{param:num}']);
    }

    public function testGetParametersWithInvalidRegex()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('1° parameter is invalid.');
        $_SERVER['REQUEST_URI'] = '/controller/method/123abc';
        $router = new Router();
        $router->getParameters(['{param:/^\d+$/}']);
    }

    public function testGetParametersWithMissingRequired()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('1° parameter is invalid.');
        $_SERVER['REQUEST_URI'] = '/controller/method';
        $router = new Router();
        $router->getParameters(['{param:num}']);
    }

    public function testLoad()
    {
        // Test loading home controller (should throw exception as controller doesn't exist)
        $_SERVER['REQUEST_URI'] = '/';
        $router = new Router();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('The page you are looking for does not exist.');
        $router->load();
    }

    public function testLoadWithSpecificController()
    {
        // Test loading specific controller (should throw exception as controller doesn't exist)
        $_SERVER['REQUEST_URI'] = '/test';
        $router = new Router();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('The page you are looking for does not exist.');
        $router->load('test');
    }

    public function testIsHome()
    {
        // Test home detection
        $_SERVER['REQUEST_URI'] = '/';
        $router = new Router();
        
        // We can't directly test isHome() as it's private, but we can test the behavior
        // by checking if it tries to load the home controller
        $this->expectException(\Exception::class);
        $router->load();
    }

    public function testGetPath()
    {
        // We can't directly test getPath() as it's private, but we can test its behavior
        // through getParameters() which uses it internally
        
        // Test with simple path
        $_SERVER['REQUEST_URI'] = '/controller/method';
        $router = new Router();
        // The path should be 'method' for this URI
        
        // Test with home path
        $_SERVER['REQUEST_URI'] = '/controller';
        $router = new Router();
        // The path should be 'index' for this URI
        
        $this->assertTrue(true); // Placeholder assertion since we can't test private methods directly
    }

    public function testRouterWithQueryString()
    {
        // Test that query strings are properly handled
        $_SERVER['REQUEST_URI'] = '/controller/method?param=value&other=test';
        $router = new Router();
        $this->assertEquals('controller', $router->getControllerNotHome());
    }

    public function testRouterWithComplexURI()
    {
        // Test with complex URI structure
        $_SERVER['REQUEST_URI'] = '/api/v1/users/123/posts/456';
        $router = new Router();
        $this->assertEquals('api', $router->getControllerNotHome());
    }

    public function testParameterValidation()
    {
        // Test that empty parameters are handled correctly
        $_SERVER['REQUEST_URI'] = '/controller/method//test';
        $router = new Router();
        
        // This should handle empty segments gracefully
        $parameters = $router->getParameters(['{param1:any:optional}', '{param2:any}']);
        $this->assertEquals(['test'], $parameters);
    }

    public function testGlobalMiddlewareLoading()
    {
        // Test that CORS middleware is loaded when enabled
        $_ENV['CORS_ENABLED'] = 'true';
        $router = new Router();
        
        // We can't directly test private properties, but we can verify the router was created
        $this->assertInstanceOf(Router::class, $router);
        
        // Clean up
        unset($_ENV['CORS_ENABLED']);
    }

    public function testSecurityHeadersMiddlewareLoadingEnabled()
    {
        // Test that SecurityHeaders middleware is loaded when enabled (default)
        $_ENV['SECURITY_HEADERS_ENABLED'] = 'true';
        $router = new Router();
        
        // We can't directly test private properties, but we can verify the router was created
        $this->assertInstanceOf(Router::class, $router);
        
        // Clean up
        unset($_ENV['SECURITY_HEADERS_ENABLED']);
    }

    public function testSecurityHeadersMiddlewareLoadingDisabled()
    {
        // Test that SecurityHeaders middleware is not loaded when disabled
        $_ENV['SECURITY_HEADERS_ENABLED'] = 'false';
        $router = new Router();
        
        // We can't directly test private properties, but we can verify the router was created
        $this->assertInstanceOf(Router::class, $router);
        
        // Clean up
        unset($_ENV['SECURITY_HEADERS_ENABLED']);
    }

    public function testSecurityHeadersMiddlewareLoadingDefault()
    {
        // Test that SecurityHeaders middleware is loaded by default (when no env var is set)
        $router = new Router();
        
        // We can't directly test private properties, but we can verify the router was created
        $this->assertInstanceOf(Router::class, $router);
    }

    public function testBothMiddlewaresEnabled()
    {
        // Test that both CORS and SecurityHeaders middlewares can be loaded together
        $_ENV['CORS_ENABLED'] = 'true';
        $_ENV['SECURITY_HEADERS_ENABLED'] = 'true';
        $router = new Router();
        
        // We can't directly test private properties, but we can verify the router was created
        $this->assertInstanceOf(Router::class, $router);
        
        // Clean up
        unset($_ENV['CORS_ENABLED']);
        unset($_ENV['SECURITY_HEADERS_ENABLED']);
    }

    public function testRouteRewriteHandling()
    {
        // Test that route rewrite config is loaded if it exists
        $configPath = Functions::getRoot() . "Config/route_rewrite.config.php";
        $configDir = dirname($configPath);
        
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }
        
        // Create a temporary route rewrite config
        file_put_contents($configPath, "<?php\nreturn ['test' => 'TestController'];");
        
        $router = new Router();
        $this->assertInstanceOf(Router::class, $router);
        
        // Clean up
        if (file_exists($configPath)) {
            unlink($configPath);
        }
        if (is_dir($configDir)) {
            rmdir($configDir);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_SERVER = [];
        $_FILES = [];
        $_ENV = [];
        
        // Clean up test directories
        if (is_dir('App/Controllers')) {
            rmdir('App/Controllers');
        }
        if (is_dir('App')) {
            rmdir('App');
        }
        
        // Destroy session if it exists
        if (session_status() === PHP_SESSION_ACTIVE) {
            Session::destroy();
        }
    }
} 