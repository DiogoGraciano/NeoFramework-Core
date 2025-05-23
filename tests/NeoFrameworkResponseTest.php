<?php

namespace Tests;

use NeoFramework\Core\Response;
use PHPUnit\Framework\TestCase;

class NeoFrameworkResponseTest extends TestCase
{
    private Response $response;

    protected function setUp(): void
    {
        parent::setUp();
        $this->response = new Response();
    }

    public function testSetAndGetCode()
    {
        $this->response->setCode(404);
        $this->assertEquals(404, $this->response->getCode());
    }

    public function testIsSent()
    {
        $this->assertFalse($this->response->isSent());
    }

    public function testSetAndGetHeader()
    {
        $this->response->setHeader('Content-Type', 'application/json');
        $this->assertEquals(['application/json'], $this->response->getHeader('Content-Type'));
    }

    public function testAddHeader()
    {
        $this->response->addHeader('X-Custom', 'value1');
        $this->response->addHeader('X-Custom', 'value2');
        
        $headers = $this->response->getHeader('X-Custom');
        $this->assertCount(2, $headers);
        $this->assertEquals('value1', $headers[0]);
        $this->assertEquals('value2', $headers[1]);
    }

    public function testGetHeaders()
    {
        $this->response->setHeader('Header1', 'value1');
        $this->response->setHeader('Header2', 'value2');
        
        $headers = $this->response->getHeaders();
        $this->assertArrayHasKey('Header1', $headers);
        $this->assertArrayHasKey('Header2', $headers);
        $this->assertEquals(['value1'], $headers['Header1']);
        $this->assertEquals(['value2'], $headers['Header2']);
    }

    public function testSetContentType()
    {
        $this->response->setContentType('application/json', 'UTF-8');
        $this->assertEquals(['application/json; charset=UTF-8'], $this->response->getHeader('Content-Type'));
    }

    public function testSetExpiration()
    {
        $time = '2024-12-31 23:59:59';
        $this->response->setExpiration($time);
        
        $expires = $this->response->getHeader('Expires');
        $this->assertNotNull($expires);
        $this->assertStringStartsWith('Tue, 31 Dec 2024 23:59:59', $expires[0]);
    }

    public function testSetAndDeleteCookie()
    {
        $this->response->setCookie('test_cookie', 'value', time() + 3600, '/', 'example.com', true, true, 'Strict');
        $this->response->deleteCookie('test_cookie', '/', 'example.com', true);
        
        // Note: We can't directly test cookie setting as it uses PHP's setcookie function
        // This test mainly ensures the methods don't throw errors
        $this->assertTrue(true);
    }

    public function testGoAndGoToSite()
    {
        $this->response->go('/test/path');
        $this->response->goToSite('https://example.com');
        
        $headers = $this->response->getHeaders();
        $this->assertArrayHasKey('Location', $headers);
    }

    public function testAddContent()
    {
        // Test string content
        $this->response->addContent('Hello World');
        $this->assertEquals(['Hello World'], $this->response->getContents());
        
        // Test array content
        $array = ['key' => 'value'];
        $this->response->addContent($array);
        $this->assertStringContainsString('"key":"value"', $this->response->getContent());
        
        // Test object content
        $object = new \stdClass();
        $object->key = 'value';
        $this->response->addContent($object);
        $this->assertStringContainsString('"key":"value"', $this->response->getContent());
    }

    public function testGetContent()
    {
        $this->response->addContent('Hello');
        $this->response->addContent(' World');
        
        $this->assertEquals('Hello World', $this->response->getContent());
    }

    public function testSend()
    {
        $this->response->setCode(200);
        $this->response->setHeader('Content-Type', 'text/plain');
        $this->response->addContent('Test Content');
        
        // Note: We can't fully test send() as it calls exit
        // This test mainly ensures the method doesn't throw errors before exit
        $this->expectException(\Exception::class);
        $this->response->send();
        $this->response->send(); // This should throw an exception
    }
} 