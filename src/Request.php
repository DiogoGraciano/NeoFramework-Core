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

    public function get(string $var)
    {
        if (isset($this->get[$var]))
            return $this->get[$var];
        else
            return null;
    }

    public function post(string $var)
    {
        if (isset($this->post[$var]))
            return $this->post[$var];
        else
            return null;
    }

    public function cookie(string $var)
    {
        if (isset($this->cookie[$var]))
            return $this->cookie[$var];
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

    public function file(string $var)
    {
        if (isset($this->files[$var]))
            return $this->processFiles($this->files[$var]);
        else
            return null;
    }

    public function getArray()
    {
        return $this->get;
    }

    public function postArray()
    {
        return $this->post;
    }

    public function cookieArray()
    {
        return $this->cookie;
    }

    public function serverArray()
    {
        return $this->server;
    }

    public function filesArray()
    {
        return $this->processFiles($this->files);
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
                $splFiles[] = new SplFileObject($fileInfo['tmp_name']);
            }
        }

        return $splFiles;
    }

    private function isJsonContentType(): bool
    {
        return stripos($this->contentType(), 'application/json') === 0;
    }
}
