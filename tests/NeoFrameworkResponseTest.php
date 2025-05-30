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
        
        $this->response->setCode(200);
        $this->assertEquals(200, $this->response->getCode());
        
        $this->response->setCode(500);
        $this->assertEquals(500, $this->response->getCode());
    }

    public function testIsSent()
    {
        $this->assertFalse($this->response->isSent());
    }

    public function testSetAndGetHeader()
    {
        $this->response->setHeader('Content-Type', 'application/json');
        $this->assertEquals(['application/json'], $this->response->getHeader('Content-Type'));
        
        // Test overwriting header
        $this->response->setHeader('Content-Type', 'text/html');
        $this->assertEquals(['text/html'], $this->response->getHeader('Content-Type'));
        
        // Test non-existent header
        $this->assertNull($this->response->getHeader('NonExistent'));
    }

    public function testAddHeader()
    {
        $this->response->addHeader('X-Custom', 'value1');
        $this->response->addHeader('X-Custom', 'value2');
        
        $headers = $this->response->getHeader('X-Custom');
        $this->assertCount(2, $headers);
        $this->assertEquals('value1', $headers[0]);
        $this->assertEquals('value2', $headers[1]);
        
        // Test adding to non-existent header
        $this->response->addHeader('X-New', 'new-value');
        $this->assertEquals(['new-value'], $this->response->getHeader('X-New'));
    }

    public function testGetHeaders()
    {
        $this->response->setHeader('Header1', 'value1');
        $this->response->setHeader('Header2', 'value2');
        $this->response->addHeader('Header3', 'value3a');
        $this->response->addHeader('Header3', 'value3b');
        
        $headers = $this->response->getHeaders();
        $this->assertArrayHasKey('Header1', $headers);
        $this->assertArrayHasKey('Header2', $headers);
        $this->assertArrayHasKey('Header3', $headers);
        $this->assertEquals(['value1'], $headers['Header1']);
        $this->assertEquals(['value2'], $headers['Header2']);
        $this->assertEquals(['value3a', 'value3b'], $headers['Header3']);
    }

    public function testSetContentType()
    {
        // Test without charset
        $this->response->setContentType('application/json');
        $this->assertEquals(['application/json'], $this->response->getHeader('Content-Type'));
        
        // Test with charset
        $this->response->setContentType('text/html', 'UTF-8');
        $this->assertEquals(['text/html; charset=UTF-8'], $this->response->getHeader('Content-Type'));
        
        // Test with null charset
        $this->response->setContentType('application/xml', null);
        $this->assertEquals(['application/xml'], $this->response->getHeader('Content-Type'));
    }

    public function testSetExpiration()
    {
        // Test with specific time
        $time = '2024-12-31 23:59:59';
        $this->response->setExpiration($time);
        
        $expires = $this->response->getHeader('Expires');
        $this->assertNotNull($expires);
        $this->assertIsArray($expires);
        $this->assertCount(1, $expires);
        $this->assertStringContainsString('GMT', $expires[0]);
        
        // Test with null (should set to '0')
        $this->response->setExpiration(null);
        $expires = $this->response->getHeader('Expires');
        $this->assertEquals(['0'], $expires);
    }

    public function testSetAndDeleteCookie()
    {
        // Test setting cookie with all parameters
        $result = $this->response->setCookie(
            'test_cookie', 
            'value', 
            time() + 3600, 
            '/', 
            'example.com', 
            true, 
            true, 
            'Strict'
        );
        $this->assertInstanceOf(Response::class, $result);
        
        // Test deleting cookie
        $result = $this->response->deleteCookie('test_cookie', '/', 'example.com', true);
        $this->assertInstanceOf(Response::class, $result);
        
        // Test with DateTime object
        $expiry = new \DateTime('+1 hour');
        $result = $this->response->setCookie('datetime_cookie', 'value', $expiry);
        $this->assertInstanceOf(Response::class, $result);
        
        // Test with string expiry
        $result = $this->response->setCookie('string_cookie', 'value', '+2 hours');
        $this->assertInstanceOf(Response::class, $result);
    }

    public function testGoAndGoToSite()
    {
        // Mock the Url::getUrlBase() method by setting up $_SERVER
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['HTTPS'] = 'off';
        
        $this->response->go('/test/path');
        $headers = $this->response->getHeaders();
        $this->assertArrayHasKey('Location', $headers);
        
        // Test goToSite with absolute URL
        $this->response->goToSite('https://external.com/path');
        $headers = $this->response->getHeaders();
        $this->assertArrayHasKey('Location', $headers);
        $this->assertEquals(['https://external.com/path'], $headers['Location']);
    }

    public function testAddContent()
    {
        // Test string content
        $this->response->addContent('Hello World');
        $this->assertEquals(['Hello World'], $this->response->getContents());
        
        // Test multiple string contents
        $this->response->addContent(' Additional');
        $this->assertEquals(['Hello World', ' Additional'], $this->response->getContents());
        
        // Test array content
        $array = ['key' => 'value', 'number' => 123];
        $this->response->addContent($array);
        $contents = $this->response->getContents();
        $this->assertCount(3, $contents);
        $this->assertStringContainsString('"key":"value"', $contents[2]);
        $this->assertStringContainsString('"number":123', $contents[2]);
        
        // Test object content
        $object = new \stdClass();
        $object->key = 'value';
        $object->number = 456;
        $this->response->addContent($object);
        $contents = $this->response->getContents();
        $this->assertCount(4, $contents);
        $this->assertStringContainsString('"key":"value"', $contents[3]);
        $this->assertStringContainsString('"number":456', $contents[3]);
    }

    public function testGetContent()
    {
        $this->response->addContent('Hello');
        $this->response->addContent(' ');
        $this->response->addContent('World');
        
        $this->assertEquals('Hello World', $this->response->getContent());
        
        // Test with empty content
        $emptyResponse = new Response();
        $this->assertEquals('', $emptyResponse->getContent());
    }

    public function testMethodChaining()
    {
        $result = $this->response
            ->setCode(201)
            ->setHeader('Content-Type', 'application/json')
            ->addHeader('X-Custom', 'value')
            ->setContentType('application/json', 'UTF-8')
            ->addContent(['message' => 'success']);
            
        $this->assertInstanceOf(Response::class, $result);
        $this->assertEquals(201, $this->response->getCode());
        $this->assertEquals(['application/json; charset=UTF-8'], $this->response->getHeader('Content-Type'));
        $this->assertEquals(['value'], $this->response->getHeader('X-Custom'));
        $this->assertStringContainsString('"message":"success"', $this->response->getContent());
    }

    public function testSendThrowsExceptionWhenAlreadySent()
    {
        // We can't fully test send() as it calls exit and headers
        // But we can test that calling send twice throws an exception
        
        $this->response->setCode(200);
        $this->response->addContent('Test Content');
        
        // Mock the isSent state by reflection to test the exception
        $reflection = new \ReflectionClass($this->response);
        $isSentProperty = $reflection->getProperty('isSent');
        $isSentProperty->setAccessible(true);
        $isSentProperty->setValue($this->response, true);
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Response already sent');
        $this->response->send();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Clean up $_SERVER if modified
        unset($_SERVER['HTTP_HOST'], $_SERVER['HTTPS']);
    }
} 