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

    /**
     * Busca um cabeçalho sem diferenciar maiúsculas/minúsculas, como manda o HTTP.
     */
    public function getHeader(string $name): ?string
    {
        if (isset($this->headers[$name])) {
            return $this->headers[$name];
        }

        $needle = strtolower($name);

        foreach ($this->headers as $header => $value) {
            if (strtolower($header) === $needle) {
                return $value;
            }
        }

        return null;
    }

    public function get(string $var,bool $sanitazed = false)
    {
        if (isset($this->get[$var]))
            return $sanitazed?$this->sanitizeData($this->get[$var]):$this->get[$var];
        else
            return null;
    }

    public function post(string $var,bool $sanitazed = false)
    {
        if (isset($this->post[$var]))
            return $sanitazed?$this->sanitizeData($this->post[$var]):$this->post[$var];
        else
            return null;
    }

    public function cookie(string $var,bool $sanitazed = false)
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

    public function getMethod():string|null
    {
        return $this->server("REQUEST_METHOD");
    }

    /**
     * Token CSRF enviado no corpo, na query ou no cabeçalho X-CSRF-TOKEN.
     */
    public function getCsrfToken():null|string
    {
        $token = $this->post("CSRF_TOKEN") ?? $this->get("CSRF_TOKEN") ?? $this->getHeader("X-CSRF-TOKEN");

        return is_string($token) ? $token : null;
    }

    public function getArray(bool $sanitazed = false)
    {
        return $sanitazed?$this->sanitizeData($this->get):$this->get;
    }

    public function postArray(bool $sanitazed = false)
    {
        return $sanitazed?$this->sanitizeData($this->post):$this->post;
    }

    public function cookieArray(bool $sanitazed = false)
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

    /**
     * Reúne os dados da requisição: query string, corpo e arquivos.
     *
     * Cookies ficam de fora de propósito. Eles são plantáveis por qualquer
     * subdomínio e, se participassem da mescla, sobrescreveriam campos de
     * formulário. Use cookieArray() quando precisar deles.
     *
     * Precedência, do menor para o maior: query < corpo < JSON < arquivos.
     */
    public function all(): array
    {
        $all = array_merge($this->getArray(), $this->postArray());

        $body = $this->getBodyAsJson(true);

        if ($this->isJsonContentType() && is_array($body)) {
            $all = array_merge($all, $body);
        }

        return array_merge($all, $this->filesArray());
    }

    public function contentType(): string
    {
        return $this->server('CONTENT_TYPE') ?: '';
    }

    private function processFiles(array $fileData): array
    {
        if (!isset($fileData['name'], $fileData['tmp_name'], $fileData['error'])) {
            return [];
        }

        $isMulti = is_array($fileData['name']);
        $fileKeys = array_keys($fileData);

        if ($isMulti) {
            $transposed = array_map(null, ...array_values($fileData));
            $fileList = array_map(function ($data) use ($fileKeys) {
                return array_combine($fileKeys, is_array($data) ? $data : [$data]);
            }, $transposed);
        } else {
            $fileList = [$fileData];
        }

        $splFiles = [];
        foreach ($fileList as $fileInfo) {
            if (!is_array($fileInfo) || ($fileInfo['error'] ?? null) !== UPLOAD_ERR_OK) {
                continue;
            }

            // Garante que o caminho veio de um upload HTTP e não foi forjado
            // para apontar para um arquivo qualquer do servidor.
            if (!$this->isUploadedFile($fileInfo['tmp_name'])) {
                continue;
            }

            $splFiles[] = new File($fileInfo['tmp_name']);
        }

        return $splFiles;
    }

    /**
     * Isolado para permitir que os testes simulem uploads sem passar por SAPI.
     */
    protected function isUploadedFile(string $tmpName): bool
    {
        if (PHP_SAPI === 'cli') {
            return is_file($tmpName);
        }

        return is_uploaded_file($tmpName);
    }

    /**
     * Escapa entidades HTML de forma recursiva.
     *
     * Não é uma defesa contra SQL injection nem substitui o escape na saída:
     * é apenas um utilitário para quando o valor vai direto para o HTML sem
     * passar pelo Template.
     */
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
