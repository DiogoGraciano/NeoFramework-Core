<?php
declare(strict_types=1);

namespace NeoFramework\Core\Abstract;

use NeoFramework\Core\Request;
use NeoFramework\Core\Response;
use Psr\Http\Message\ServerRequestInterface;

abstract class Controller
{
    protected ServerRequestInterface $request;
    protected Response $response;

    /**
     * Desativa a validação de CSRF em todos os métodos deste controller.
     *
     * Só faça isso em endpoints que não usam sessão para autenticar (uma API
     * com token no header, por exemplo).
     */
    const skipCsrfValidation = false;

    public function __construct()
    {
        $this->request = new Request();
        $this->response = new Response();
    }

    public function setRequest(ServerRequestInterface $request): static {
        $this->request = $request;
        return $this;
    }

    /** @deprecated Use setRequest(). */
    public function setResquest(ServerRequestInterface $request): static { return $this->setRequest($request); }

    public function setResponse(Response $response): static {
        $this->response = $response;

        return $this;
    }

    public function getResponse(): Response {
        return $this->response;
    }

    public function getRequest(): ServerRequestInterface {
        return $this->request;
    }

    protected function getOffset(int $limit = 30):int
    {
        return ($this->page() - 1) * $limit;
    }

    protected function getLimit(int $limit = 30):int
    {
        return $limit ?: 20;
    }

    protected function isMobile():bool
    {
        return \NeoFramework\Core\Support\UserAgent::isMobile($this->request->getHeaderLine('User-Agent'));
    }

    protected function page(): int
    {
        return max(1, (int) ($this->request->getQueryParams()['page'] ?? 1));
    }

    protected function req(): Request
    {
        if (!$this->request instanceof Request) throw new \LogicException('A requisição atual não é uma instância de NeoFramework Request.');
        return $this->request;
    }

    protected function json(mixed $data, int $status = 200): Response { return (new Response())->json($data, $status); }
    protected function html(string|Layout $content, int $status = 200): Response { return (new Response())->html($content, $status); }
    protected function text(string $content, int $status = 200): Response { return (new Response())->text($content, $status); }
    protected function redirect(string $path, int $status = 302): Response { return (new Response())->go($path, $status); }
    protected function redirectTo(string $url, int $status = 302): Response { return (new Response())->goToSite($url, $status); }
}
