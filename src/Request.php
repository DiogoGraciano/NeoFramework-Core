<?php

namespace NeoFramework\Core;

use SimpleXMLElement;
use SplFileObject;

final class Request
{
    private array $get;
    private array $post;
    private array $cookie;
    private array $server;
    private array $files;
    private string|null|false $body;
    private array $headers;

    public function __construct()
    {
        $this->get = &$_GET;
        $this->post = &$_POST;
        $this->cookie = &$_COOKIE;
        $this->server = &$_SERVER;
        $this->files = &$_FILES;
        $this->body = file_get_contents('php://input');
        $this->headers = self::getAllHeaders();
    }

    public static function isXmlHttpRequest(): bool
    {
        $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) ? $_SERVER['HTTP_X_REQUESTED_WITH'] : "";
        return (strtolower($isAjax) === 'xmlhttprequest');
    }

    public static function getAllHeaders(): array
    {
        $headers = [];
        
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
        } else {
            foreach ($_SERVER as $name => $value) {
                if (str_starts_with($name, 'HTTP_')) {
                    $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                    $headers[$headerName] = $value;
                }
            }
        }

        $specialHeaders = ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'];
        foreach ($specialHeaders as $header) {
            if (isset($_SERVER[$header])) {
                $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $header))));
                $headers[$headerName] = $_SERVER[$header];
            }
        }

        return $headers;
    }

    public function addHeader(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    public function get(string $var,bool $sanitazed = true)
    {
        if (isset($this->get[$var]))
            return $sanitazed?$this->sanitizeData($this->get[$var]):$this->get[$var];
        else
            return null;
    }

    public function post(string $var,bool $sanitazed = true)
    {
        if (isset($this->post[$var]))
            return $sanitazed?$this->sanitizeData($this->post[$var]):$this->post[$var];
        else
            return null;
    }

    public function cookie(string $var,bool $sanitazed = true)
    {
        if (isset($this->cookie[$var]))
            return $sanitazed?$this->sanitizeData($this->cookie[$var]):$this->cookie[$var];
        else
            return null;
    }

    public function server(string $var)
    {
        if (isset($this->server[$var]))
            return $this->server[$var];
        else
            return null;
    }

    public function file(string $var):SplFileObject|array|null
    {
        if (isset($this->files[$var])){
            $file = $this->processFiles($this->files[$var]);
            if(empty($file))
                return null;

            return count($file) == 1?$file[0]:$file;
        }else
            return null;
    }

    public function getCsrfToken():null|string
    {
        return $this->post("CSRF_TOKEN") ?? $this->get("CSRF_TOKEN") ?? $this->server("X-CSRF-TOKEN");
    }

    public function getArray(bool $sanitazed = true)
    {
        return $sanitazed?$this->sanitizeData($this->get):$this->get;
    }

    public function postArray(bool $sanitazed = true)
    {
        return $sanitazed?$this->sanitizeData($this->post):$this->post;
    }

    public function cookieArray(bool $sanitazed = true)
    {
        return $sanitazed?$this->sanitizeData($this->cookie):$this->cookie;
    }

    public function serverArray()
    {
        return $this->server;
    }

    public function filesArray()
    {
        return $this->files;
    }

    public function getBody(): string
    {
        return $this->body ?? "";
    }

    public function setBody(string $body): void
    {
        $this->body = $body;
    }

    public function setBodyAsJson(array $json): void
    {
        $this->body = json_encode($json);
    }

    public function setBodyAsXml(SimpleXMLElement $xml): void
    {
        $this->body = $xml->asXML();
    }

    public function getBodyAsJson($asArray = false): mixed
    {
        return json_decode($this->getBody(), $asArray);
    }

    public function getBodyAsXml(): SimpleXMLElement|false
    {
        return simplexml_load_string($this->getBody());
    }

    public function all(): array
    {
        $all = [];

        $all = array_merge($this->postArray(), $this->getArray(), $this->cookieArray(), $this->filesArray());

        $body = $this->getBodyAsJson(true);

        if ($this->isJsonContentType() && is_array($body)) {
            $all = array_merge($all, $body);
        }

        return $all;
    }

    public function contentType(): string
    {
        return $this->server('CONTENT_TYPE') ?: '';
    }

    private function processFiles(array $fileData): array
    {
        $isMulti = is_array($fileData['name']);
        $fileKeys = array_keys($fileData);

        if ($isMulti) {
            $transposed = array_map(null, ...array_values($fileData));
            $fileList = array_map(function ($data) use ($fileKeys) {
                return array_combine($fileKeys, $data);
            }, $transposed);
        } else {
            $fileList = [$fileData];
        }

        $splFiles = [];
        foreach ($fileList as $fileInfo) {
            if ($fileInfo['error'] === UPLOAD_ERR_OK) {
                $splFiles[] = new File($fileInfo['tmp_name']);
            }
        }

        return $splFiles;
    }

    private function sanitizeData($data)
    {
        if (is_array($data)) {
            $sanitized = [];
            foreach ($data as $key => $value) {
                $sanitized[$key] = $this->sanitizeData($value);
            }
            return $sanitized;
        } elseif (is_string($data)) {
            return htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } else {
            return $data;
        }
    }

    private function isJsonContentType(): bool
    {
        return stripos($this->contentType(), 'application/json') === 0;
    }
}
