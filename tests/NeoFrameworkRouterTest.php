<?php

namespace Tests;

use NeoFramework\Core\Router;
use NeoFramework\Core\Session;
use PHPUnit\Framework\TestCase;

class NeoFrameworkRouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new Router();
    }

    public function testGetNamespace()
    {
        // Test default namespace
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

        // Test regex parameter
        $_SERVER['REQUEST_URI'] = '/controller/method/abc123';
        $router = new Router();
        $parameters = $router->getParameters(['{param:/^[a-z]+\d+$/}']);
        $this->assertEquals(['abc123'], $parameters);

        // Test optional parameter
        $_SERVER['REQUEST_URI'] = '/controller/method';
        $router = new Router();
        $parameters = $router->getParameters(['{param:any:optional}']);
        $this->assertEquals([], $parameters);

        // Test invalid parameter
        $this->expectException(\Exception::class);
        $_SERVER['REQUEST_URI'] = '/controller/method/abc';
        $router = new Router();
        $router->getParameters(['{param:num}']);
    }

    public function testLoad()
    {
        // Test loading home controller
        $_SERVER['REQUEST_URI'] = '/';
        $router = new Router();
        $this->expectException(\Exception::class);
        $router->load();

        // Test loading specific controller
        $_SERVER['REQUEST_URI'] = '/test';
        $router = new Router();
        $this->expectException(\Exception::class);
        $router->load('test');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $_SERVER = [];
        Session::destroy();
    }
} 