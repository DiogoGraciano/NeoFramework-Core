<?php

namespace NeoFramework\Core\Exceptions;

use NeoFramework\Core\Response;

/**
 * Interrompe o processamento da requisição devolvendo uma resposta pronta.
 *
 * Existe para que middlewares e verificações possam encerrar o fluxo (um 403 de
 * CSRF, um preflight CORS) sem chamar exit — o que tornava o framework
 * impossível de testar e matava os middlewares "after" pela metade.
 */
class HttpResponseException extends \RuntimeException
{
    public function __construct(private Response $response, string $message = "")
    {
        parent::__construct($message ?: "Request interrupted with a prepared response.", $response->getCode());
    }

    public function getResponse(): Response
    {
        return $this->response;
    }
}
