<?php

namespace NeoFramework\Core;

use SplFileObject;

final class Request
{
    private readonly array $get;
    private readonly array $post;
    private readonly array $cookie;
    private readonly array $server;
    private readonly array $files;

    public function __construct()
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $callerClass = $backtrace[1]['class'] ?? null;

        if ($callerClass !== Router::class) {
            throw new \Exception("A classe Request só pode ser instanciada pela classe Router.");
        }

        $this->get = $_GET;
        $this->post = $_POST;
        $this->cookie = $_COOKIE;
        $this->server = $_SERVER;
        $this->files = $_FILES;
    }

    public static function isXmlHttpRequest(): bool
    {
        $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) ? $_SERVER['HTTP_X_REQUESTED_WITH'] : "";
        return (strtolower($isAjax) === 'xmlhttprequest');
    }

    public static function getAllHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }

    public function getMethod(): string
    {
        return $_SERVER['REQUEST_METHOD'];
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
        return file_get_contents('php://input') ?? "";
    }

    public function getBodyAsJson($asArray = false): mixed
    {
        return json_decode($this->getBody(), $asArray);
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
