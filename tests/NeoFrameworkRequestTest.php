<?php

namespace Tests;

use NeoFramework\Core\Request;
use NeoFramework\Core\File;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

class NeoFrameworkRequestTest extends TestCase
{
    private Request $request;

    protected function setUp(): void
    {
        parent::setUp();
        // Clear superglobals before each test
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_SERVER = [];
        $_FILES = [];
        
        $this->request = new Request();
    }

    public function testIsXmlHttpRequest()
    {
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'xmlhttprequest';
        $this->assertTrue(Request::isXmlHttpRequest());

        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
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
        $_SERVER['CONTENT_LENGTH'] = '100';

        $headers = Request::getAllHeaders();
        
        $this->assertArrayHasKey('Accept', $headers);
        $this->assertArrayHasKey('User-Agent', $headers);
        $this->assertArrayHasKey('Content-Type', $headers);
        $this->assertArrayHasKey('Content-Length', $headers);
        $this->assertEquals('application/json', $headers['Accept']);
        $this->assertEquals('PHPUnit', $headers['User-Agent']);
        $this->assertEquals('application/json', $headers['Content-Type']);
        $this->assertEquals('100', $headers['Content-Length']);
    }

    public function testAddAndGetHeader()
    {
        $this->request->addHeader('X-Custom-Header', 'custom-value');
        $this->assertEquals('custom-value', $this->request->getHeader('X-Custom-Header'));
        $this->assertNull($this->request->getHeader('NonExistent'));
    }

    public function testGetPostCookieMethods()
    {
        $_GET['test_get'] = 'get_value';
        $_POST['test_post'] = 'post_value';
        $_COOKIE['test_cookie'] = 'cookie_value';

        // Create new request to pick up the new superglobal values
        $request = new Request();

        $this->assertEquals('get_value', $request->get('test_get'));
        $this->assertEquals('post_value', $request->post('test_post'));
        $this->assertEquals('cookie_value', $request->cookie('test_cookie'));

        // Test non-existent keys
        $this->assertNull($request->get('non_existent'));
        $this->assertNull($request->post('non_existent'));
        $this->assertNull($request->cookie('non_existent'));

        // Test sanitization
        $_GET['unsafe'] = '<script>alert("xss")</script>';
        $request = new Request();

        $this->assertEquals('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $request->get('unsafe'));
        $this->assertEquals('<script>alert("xss")</script>', $request->get('unsafe', false));
    }

    public function testServerMethod()
    {
        $_SERVER['TEST_SERVER'] = 'server_value';
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $request = new Request();

        $this->assertEquals('server_value', $request->server('TEST_SERVER'));
        $this->assertEquals('POST', $request->server('REQUEST_METHOD'));
        $this->assertNull($request->server('NON_EXISTENT'));
    }

    public function testGetMethod()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $request = new Request();
        $this->assertEquals('GET', $request->getMethod());

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $request = new Request();
        $this->assertEquals('POST', $request->getMethod());

        unset($_SERVER['REQUEST_METHOD']);
        $request = new Request();
        $this->assertNull($request->getMethod());
    }

    public function testFileMethod()
    {
        // Test with no file uploaded (error)
        $_FILES['test_file'] = [
            'name' => 'test.txt',
            'type' => 'text/plain',
            'tmp_name' => '/tmp/test.txt',
            'error' => UPLOAD_ERR_NO_FILE,
            'size' => 123
        ];

        $request = new Request();
        $file = $request->file('test_file');
        $this->assertNull($file);

        // Test with non-existent file key
        $this->assertNull($request->file('non_existent'));
    }

    public function testGetCsrfToken()
    {
        // Test POST token priority
        $_POST['CSRF_TOKEN'] = 'post_token';
        $_GET['CSRF_TOKEN'] = 'get_token';
        $_SERVER['X-CSRF-TOKEN'] = 'header_token';
        
        $request = new Request();
        $this->assertEquals('post_token', $request->getCsrfToken());

        // Test GET token fallback
        unset($_POST['CSRF_TOKEN']);
        $request = new Request();
        $this->assertEquals('get_token', $request->getCsrfToken());

        // Test header token fallback
        unset($_GET['CSRF_TOKEN']);
        $request = new Request();
        $this->assertEquals('header_token', $request->getCsrfToken());

        // Test no token
        unset($_SERVER['X-CSRF-TOKEN']);
        $request = new Request();
        $this->assertNull($request->getCsrfToken());
    }

    public function testArrayMethods()
    {
        $_GET = ['key1' => 'value1', 'key2' => '<script>'];
        $_POST = ['post1' => 'value1', 'post2' => '<script>'];
        $_COOKIE = ['cookie1' => 'value1', 'cookie2' => '<script>'];
        $_FILES = ['file1' => ['name' => 'test.txt']];

        $request = new Request();

        // Test unsanitized arrays
        $this->assertEquals($_GET, $request->getArray(false));
        $this->assertEquals($_POST, $request->postArray(false));
        $this->assertEquals($_COOKIE, $request->cookieArray(false));
        $this->assertEquals($_FILES, $request->filesArray());

        // Test sanitized arrays
        $sanitizedGet = $request->getArray(true);
        $this->assertEquals('&lt;script&gt;', $sanitizedGet['key2']);
        
        $sanitizedPost = $request->postArray(true);
        $this->assertEquals('&lt;script&gt;', $sanitizedPost['post2']);
        
        $sanitizedCookie = $request->cookieArray(true);
        $this->assertEquals('&lt;script&gt;', $sanitizedCookie['cookie2']);
    }

    public function testBodyMethods()
    {
        $request = new Request();

        // Test setting and getting body
        $testBody = 'test body content';
        $request->setBody($testBody);
        $this->assertEquals($testBody, $request->getBody());

        // Test JSON body
        $jsonData = ['key' => 'value', 'number' => 123];
        $request->setBodyAsJson($jsonData);
        $this->assertEquals(json_encode($jsonData), $request->getBody());
        $this->assertEquals($jsonData, $request->getBodyAsJson(true));
        $this->assertEquals((object)$jsonData, $request->getBodyAsJson(false));

        // Test XML body
        $xml = new SimpleXMLElement('<root><item>value</item></root>');
        $request->setBodyAsXml($xml);
        $this->assertStringContainsString('<root><item>value</item></root>', $request->getBody());
        
        $parsedXml = $request->getBodyAsXml();
        $this->assertInstanceOf(SimpleXMLElement::class, $parsedXml);
        $this->assertEquals('value', (string)$parsedXml->item);

        // Test invalid XML
        $request->setBody('invalid xml');
        $this->assertFalse($request->getBodyAsXml());
    }

    public function testContentType()
    {
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $request = new Request();
        $this->assertEquals('application/json', $request->contentType());

        unset($_SERVER['CONTENT_TYPE']);
        $request = new Request();
        $this->assertEquals('', $request->contentType());
    }

    public function testAllMethod()
    {
        $_GET = ['get_key' => 'get_value'];
        $_POST = ['post_key' => 'post_value'];
        $_COOKIE = ['cookie_key' => 'cookie_value'];
        $_FILES = ['file_key' => ['name' => 'test.txt']];

        $request = new Request();
        $all = $request->all();
        
        $this->assertArrayHasKey('get_key', $all);
        $this->assertArrayHasKey('post_key', $all);
        $this->assertArrayHasKey('cookie_key', $all);
        $this->assertArrayHasKey('file_key', $all);
    }

    public function testAllMethodWithJsonBody()
    {
        $_GET = ['get_key' => 'get_value'];
        $_POST = ['post_key' => 'post_value'];
        $_SERVER['CONTENT_TYPE'] = 'application/json';

        $request = new Request();
        $jsonData = ['json_key' => 'json_value'];
        $request->setBodyAsJson($jsonData);

        $all = $request->all();
        
        $this->assertArrayHasKey('get_key', $all);
        $this->assertArrayHasKey('post_key', $all);
        $this->assertArrayHasKey('json_key', $all);
        $this->assertEquals('json_value', $all['json_key']);
    }

    public function testSanitizeDataRecursive()
    {
        $_GET = [
            'simple' => '<script>alert("xss")</script>',
            'nested' => [
                'level1' => '<img src=x onerror=alert(1)>',
                'level2' => [
                    'deep' => '<svg onload=alert(1)>'
                ]
            ]
        ];

        $request = new Request();
        $sanitized = $request->getArray(true);

        $this->assertEquals('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $sanitized['simple']);
        $this->assertEquals('&lt;img src=x onerror=alert(1)&gt;', $sanitized['nested']['level1']);
        $this->assertEquals('&lt;svg onload=alert(1)&gt;', $sanitized['nested']['level2']['deep']);
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