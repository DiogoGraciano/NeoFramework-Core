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

    public function testUrlQuery()
    {
        return $this->urlQuery;
    }
}

class NeoFrameworkControllerTest extends TestCase
{
    private TestController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        // Clear superglobals before each test
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_SERVER = [];
        $_FILES = [];
        
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

        $_GET['page'] = 'invalid';
        $controller = new TestController();
        $this->assertEquals(1, $controller->testPage());

        $_GET['page'] = '0';
        $controller = new TestController();
        $this->assertEquals(1, $controller->testPage());
    }

    public function testUrlQueryProperty()
    {
        $_GET = ['param1' => 'value1', 'param2' => 'value2'];
        $controller = new TestController();
        
        $urlQuery = $controller->testUrlQuery();
        $this->assertIsArray($urlQuery);
        $this->assertArrayHasKey('param1', $urlQuery);
        $this->assertArrayHasKey('param2', $urlQuery);
        $this->assertEquals('value1', $urlQuery['param1']);
        $this->assertEquals('value2', $urlQuery['param2']);
    }

    public function testSetAndGetRequest()
    {
        $request = new Request();
        $result = $this->controller->setResquest($request);
        
        // Test method chaining
        $this->assertInstanceOf(TestController::class, $result);
        $this->assertSame($this->controller, $result);
        
        // Test that request was set
        $this->assertInstanceOf(Request::class, $this->controller->getRequest());
        $this->assertSame($request, $this->controller->getRequest());
    }

    public function testSetAndGetResponse()
    {
        $response = new Response();
        $result = $this->controller->setResponse($response);
        
        // Test method chaining
        $this->assertInstanceOf(TestController::class, $result);
        $this->assertSame($this->controller, $result);
        
        // Test that response was set
        $this->assertInstanceOf(Response::class, $this->controller->getResponse());
        $this->assertSame($response, $this->controller->getResponse());
    }

    public function testDefaultRequestAndResponse()
    {
        // Test that controller has default request and response instances
        $this->assertInstanceOf(Request::class, $this->controller->getRequest());
        $this->assertInstanceOf(Response::class, $this->controller->getResponse());
    }

    public function testGetOffset()
    {
        // Test default offset (page 1, limit 30)
        $this->assertEquals(0, $this->controller->testOffset());

        // Test offset with custom limit
        $this->assertEquals(0, $this->controller->testOffset(50));

        // Test offset with page 2
        $_GET['page'] = '2';
        $controller = new TestController();
        $this->assertEquals(30, $controller->testOffset(30));

        // Test offset with page 3 and custom limit
        $_GET['page'] = '3';
        $controller = new TestController();
        $this->assertEquals(100, $controller->testOffset(50));

        // Test offset with page 0
        $_GET['page'] = '0';
        $controller = new TestController();
        $this->assertEquals(0, $controller->testOffset(30));
    }

    public function testGetLimit()
    {
        // Test default limit when 0 is passed
        $this->assertEquals(20, $this->controller->testLimit(0));

        // Test custom limit
        $this->assertEquals(30, $this->controller->testLimit(30));
        $this->assertEquals(50, $this->controller->testLimit(50));
        $this->assertEquals(100, $this->controller->testLimit(100));
    }

    public function testIsMobile()
    {
        // Test with mobile user agent
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.0 Mobile/15E148 Safari/604.1';
        $controller = new TestController();
        $this->assertTrue($controller->testIsMobile());

        // Test with Android user agent
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 10; SM-G975F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.120 Mobile Safari/537.36';
        $controller = new TestController();
        $this->assertTrue($controller->testIsMobile());

        // Test with desktop user agent
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';
        $controller = new TestController();
        $this->assertFalse($controller->testIsMobile());

        // Test with no user agent
        unset($_SERVER['HTTP_USER_AGENT']);
        $controller = new TestController();
        $this->assertFalse($controller->testIsMobile());
    }

    public function testValidCsrfTokenConstant()
    {
        $this->assertTrue(TestController::validCsrfToken);
    }

    public function testMethodChaining()
    {
        $request = new Request();
        $response = new Response();
        
        $result = $this->controller
            ->setResquest($request)
            ->setResponse($response);
            
        $this->assertInstanceOf(TestController::class, $result);
        $this->assertSame($request, $this->controller->getRequest());
        $this->assertSame($response, $this->controller->getResponse());
    }

    public function testControllerWithComplexPageScenarios()
    {
        // Test with negative page
        $_GET['page'] = '-1';
        $controller = new TestController();
        $this->assertEquals(-1, $controller->testPage());
        $this->assertEquals(-60, $controller->testOffset(30)); // (-1-1)*30 = -60

        // Test with very large page number
        $_GET['page'] = '1000';
        $controller = new TestController();
        $this->assertEquals(1000, $controller->testPage());
        $this->assertEquals(29970, $controller->testOffset(30)); // (1000-1)*30 = 29970
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_SERVER = [];
        $_FILES = [];
    }
} 