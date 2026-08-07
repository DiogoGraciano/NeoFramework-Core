<?php

namespace NeoFramework\Core;

final class Response
{
    private int $code = 200;
    private array $headers = [];
    private bool $isSent = false;
    private array $content = [];

    public function setCode(int $code):self
    {
        $this->code = $code;

        return $this;
    }

    public function getCode(): int
    {
        return $this->code;
    }

    public function isSent(): bool
    {
        return $this->isSent;
    }

    public function setHeader(string $name, string $value):self
    {
        $this->headers[$name] = [$value];

        return $this;
    }

    public function addHeader(string $name, string $value):self
    {
        $this->headers[$name][] = $value;

        return $this;
    }

    public function getHeader(string $name)
    {
        return $this->headers[$name] ?? null;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function setContentType(string $type, ?string $charset = null):self
    {
        $value = $type;
        if ($charset) {
            $value .= "; charset=$charset";
        }
        $this->setHeader('Content-Type', $value);

        return $this;
    }

    public function setExpiration(?string $time):self
    {
        if ($time === null) {
            $this->setHeader('Expires', '0');
        } else {
            $this->setHeader('Expires', gmdate('D, d M Y H:i:s', strtotime($time)) . ' GMT');
        }

        return $this;
    }

    public function setCookie(
        string $name,
        string $value,
        string|int|\DateTimeInterface|null $expire,
        ?string $path = null,
        ?string $domain = null,
        ?bool $secure = null,
        ?bool $httpOnly = null,
        ?string $sameSite = null
    ):self 
    {
        if ($expire instanceof \DateTimeInterface) {
            $expire = $expire->getTimestamp();
        } elseif (is_string($expire)) {
            $expire = strtotime($expire);
        }
        setcookie($name, $value, [
            'expires' => $expire,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => $httpOnly,
            'samesite' => $sameSite,
        ]);

        return $this;
    }

    public function deleteCookie(string $name, ?string $path = null, ?string $domain = null, ?bool $secure = null):self
    {
        return $this->setCookie($name, '', time() - 3600, $path, $domain, $secure, true);
    }

    /**
     * Redireciona para um caminho interno da aplicação.
     *
     * O caminho precisa ser relativo. "//evil.com" e "https://evil.com" são
     * recusados: concatenados à base eles escapariam para outro domínio.
     */
    public function go(string $caminho): self
    {
        $caminho = ltrim($caminho, '/');

        if (str_contains($caminho, '://') || str_starts_with($caminho, '/') || str_starts_with($caminho, '\\')) {
            throw new \InvalidArgumentException("go() aceita apenas caminhos internos; use goToSite() para URLs absolutas.");
        }

        $this->setHeader('Location', Url::getUrlBase() . $caminho);
        return $this;
    }

    /**
     * Redireciona para uma URL absoluta.
     *
     * Só http e https são aceitos, para barrar javascript: e data: — que
     * transformariam um redirect em execução de script.
     */
    public function goToSite(string $caminho): self
    {
        $scheme = strtolower((string) parse_url($caminho, PHP_URL_SCHEME));

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException("goToSite() aceita apenas URLs http ou https.");
        }

        $this->setHeader('Location',$caminho);
        return $this;
    }

    public function addContent(object|string|array $content):self
    {
        if(is_object($content) && is_subclass_of($content,"NeoFramework\Core\Abstract\Layout")){
            $this->content[] = $content->parse();
            return $this;
        }

        if(is_array($content) || is_object($content)){
            $this->content[] = json_encode($content);
            return $this;
        }

        $this->content[] = $content;

        return $this;
    }

    public function getContents():array
    {
        return $this->content;
    }

    public function getContent():string
    {
        return implode('', $this->content);
    }

    /**
     * Copia para esta resposta os cabeçalhos que ainda não foram definidos aqui.
     *
     * Usado quando o controller devolve uma Response nova: sem isso os
     * cabeçalhos de CORS e de segurança adicionados pelos middlewares "before"
     * seriam descartados.
     */
    public function mergeHeadersFrom(Response $other): self
    {
        foreach ($other->getHeaders() as $name => $values) {
            if (!isset($this->headers[$name])) {
                $this->headers[$name] = $values;
            }
        }

        return $this;
    }

    /**
     * Emite status, cabeçalhos e corpo.
     *
     * Não encerra o processo: quem controla o fluxo é o Router. Chamar exit aqui
     * impedia testar o framework e cortava os middlewares "after" pela metade.
     */
    public function send(): void
    {
        if ($this->isSent) {
            throw new \Exception("Response already sent");
        }

        if (!headers_sent()) {
            http_response_code($this->code);

            foreach ($this->headers as $name => $values) {
                foreach ($values as $value) {
                    header("$name: $value", false);
                }
            }
        }

        foreach($this->content as $content)
        {
            echo $content;
        }

        $this->isSent = true;
    }
}
