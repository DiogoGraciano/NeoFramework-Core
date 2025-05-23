<?php

namespace Tests;

use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Request;
use NeoFramework\Core\Response;
use PHPUnit\Framework\TestCase;

// Create a concrete test controller class
class TestController extends Controller
{
    public function testMethod()
    {
        return $this->response;
    }

    public function testPage()
    {
        return $this->page;
    }

    public function testRequest()
    {
        return $this->request;
    }

    public function testResponse()
    {
        return $this->response;
    }

    public function testOffset(int $limit = 30)
    {
        return $this->getOffset($limit);
    }

    public function testLimit(int $limit = 30)
    {
        return $this->getLimit($limit);
    }

    public function testIsMobile()      
    {
        return $this->isMobile();
    }
}

class NeoFrameworkControllerTest extends TestCase
{
    private TestController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new TestController();
    }

    public function testConstructor()
    {
        // Test default page value
        $this->assertEquals(1, $this->controller->testPage());

        // Test page from URL query
        $_GET['page'] = '2';
        $controller = new TestController();
        $this->assertEquals(2, $controller->testPage());
    }

    public function testSetAndGetRequest()
    {
        $request = new Request();
        $this->controller->setResquest($request);
        
        $this->assertInstanceOf(Request::class, $this->controller->getRequest());
        $this->assertSame($request, $this->controller->getRequest());
    }

    public function testSetAndGetResponse()
    {
        $response = new Response();
        $this->controller->setResponse($response);
        
        $this->assertInstanceOf(Response::class, $this->controller->getResponse());
        $this->assertSame($response, $this->controller->getResponse());
    }

    public function testGetOffset()
    {
        // Test default offset (page 1)
        $this->assertEquals(0, $this->controller->testOffset());

        // Test offset with custom page
        $_GET['page'] = '3';
        $controller = new TestController();
        $this->assertEquals(60, $controller->testOffset(30));
    }

    public function testGetLimit()
    {
        // Test default limit
        $this->assertEquals(20, $this->controller->testLimit(0));

        // Test custom limit
        $this->assertEquals(30, $this->controller->testLimit(30));
    }

    public function testIsMobile()
    {
        // Test with mobile user agent
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.0 Mobile/15E148 Safari/604.1';
        $this->assertTrue($this->controller->testIsMobile());

        // Test with desktop user agent
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';
        $this->assertFalse($this->controller->testIsMobile());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $_GET = [];
        $_SERVER = [];
    }
} 