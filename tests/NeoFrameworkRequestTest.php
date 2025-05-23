<?php

namespace Tests;

use NeoFramework\Core\Request;
use PHPUnit\Framework\TestCase;

class NeoFrameworkRequestTest extends TestCase
{
    private Request $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = new Request();
    }

    public function testIsXmlHttpRequest()
    {
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'xmlhttprequest';
        $this->assertTrue(Request::isXmlHttpRequest());

        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'other';
        $this->assertFalse(Request::isXmlHttpRequest());

        unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        $this->assertFalse(Request::isXmlHttpRequest());
    }

    public function testGetAllHeaders()
    {
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
        $_SERVER['CONTENT_TYPE'] = 'application/json';

        $headers = Request::getAllHeaders();
        
        $this->assertArrayHasKey('Accept', $headers);
        $this->assertArrayHasKey('User-Agent', $headers);
        $this->assertArrayHasKey('Content-Type', $headers);
        $this->assertEquals('application/json', $headers['Accept']);
        $this->assertEquals('PHPUnit', $headers['User-Agent']);
        $this->assertEquals('application/json', $headers['Content-Type']);
    }

    public function testGetHeader()
    {
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_SERVER['CONTENT_TYPE'] = 'application/json';

        //force the request to be a new instance because the static method getAllHeaders is used
        $this->request = new Request();

        $this->assertEquals('application/json', $this->request->getHeader('Accept'));
        $this->assertEquals('application/json', $this->request->getHeader('Content-Type'));

        $this->request->addHeader('Test', 'test');
        $this->assertEquals('test', $this->request->getHeader('Test'));

        $this->assertNull($this->request->getHeader('NonExistent'));
    }

    public function testGetPostCookieMethods()
    {
        $_GET['test_get'] = 'get_value';
        $_POST['test_post'] = 'post_value';
        $_COOKIE['test_cookie'] = 'cookie_value';

        $this->assertEquals('get_value', $this->request->get('test_get'));
        $this->assertEquals('post_value', $this->request->post('test_post'));
        $this->assertEquals('cookie_value', $this->request->cookie('test_cookie'));

        // Test sanitization
        $_GET['unsafe'] = '<script>alert("xss")</script>';

        $this->assertEquals('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $this->request->get('unsafe'));
        $this->assertEquals('<script>alert("xss")</script>', $this->request->get('unsafe', false));
    }

    public function testServerMethod()
    {
        $_SERVER['TEST_SERVER'] = 'server_value';

        $this->assertEquals('server_value', $this->request->server('TEST_SERVER'));
        $this->assertNull($this->request->server('NON_EXISTENT'));
    }

    public function testFileMethod()
    {
        $_FILES['test_file'] = [
            'name' => 'test.txt',
            'type' => 'text/plain',
            'tmp_name' => '/tmp/test.txt',
            'error' => UPLOAD_ERR_NO_FILE,
            'size' => 123
        ];

        $file = $this->request->file('test_file');
        $this->assertNull($file);
    }

    public function testGetCsrfToken()
    {
        $_POST['CSRF_TOKEN'] = 'post_token';
        $this->assertEquals('post_token', $this->request->getCsrfToken());

        unset($_POST['CSRF_TOKEN']);
        $_GET['CSRF_TOKEN'] = 'get_token';
        $this->assertEquals('get_token', $this->request->getCsrfToken());

        unset($_GET['CSRF_TOKEN']);
        $_SERVER['X-CSRF-TOKEN'] = 'header_token';
        $this->assertEquals('header_token', $this->request->getCsrfToken());
    }

    public function testArrayMethods()
    {
        $_GET = ['key1' => 'value1', 'key2' => 'value2'];
        $_POST = ['post1' => 'value1', 'post2' => 'value2'];
        $_COOKIE = ['cookie1' => 'value1', 'cookie2' => 'value2'];

        $this->assertEquals($_GET, $this->request->getArray(false));
        $this->assertEquals($_POST, $this->request->postArray(false));
        $this->assertEquals($_COOKIE, $this->request->cookieArray(false));
    }

    public function testBodyMethods()
    {
        $jsonData = ['key' => 'value'];
        $this->request->setBodyAsJson($jsonData);
        $this->assertEquals(json_encode($jsonData), $this->request->getBody());
        $this->assertEquals($jsonData, $this->request->getBodyAsJson(true));

        $xmlString = '<?xml version="1.0"?><root><item>value</item></root>';
        $this->request->setBody($xmlString);
        $this->assertEquals($xmlString, $this->request->getBody());
    }

    public function testAllMethod()
    {
        $_GET = ['get_key' => 'get_value'];
        $_POST = ['post_key' => 'post_value'];
        $_COOKIE = ['cookie_key' => 'cookie_value'];
        $_FILES = ['file_key' => ['name' => 'test.txt', 'type' => 'text/plain', 'tmp_name' => '/tmp/test.txt', 'error' => UPLOAD_ERR_OK, 'size' => 123]];

        $all = $this->request->all();
        $this->assertArrayHasKey('get_key', $all);
        $this->assertArrayHasKey('post_key', $all);
        $this->assertArrayHasKey('cookie_key', $all);
        $this->assertArrayHasKey('file_key', $all);
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