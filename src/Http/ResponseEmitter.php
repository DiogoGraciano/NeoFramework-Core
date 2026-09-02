<?php
declare(strict_types=1);

namespace NeoFramework\Core\Http;

use Psr\Http\Message\ResponseInterface;

final class ResponseEmitter
{
    /** Blocos de 8 KB: o suficiente para não fatiar demais nem segurar memória. */
    private const CHUNK = 8192;

    public function emit(ResponseInterface $response): void
    {
        if (!headers_sent()) {
            http_response_code($response->getStatusCode());
            foreach ($response->getHeaders() as $name => $values) {
                $replace = true;
                foreach ($values as $value) {
                    header($name . ': ' . $value, $replace);
                    $replace = false;
                }
            }
        }

        // 204 e 304 não têm corpo, e 1xx tampouco. Emitir bytes aí faz o cliente
        // tratar a resposta seguinte como lixo.
        if (self::isBodyless($response->getStatusCode())) return;

        $this->emitBody($response);
    }

    /**
     * Envia por blocos.
     *
     * `echo (string) $body` materializava a resposta inteira em memória, o que
     * anulava streaming e download de arquivo grande: um vídeo de 2 GB exigia
     * 2 GB de RAM antes do primeiro byte sair.
     */
    private function emitBody(ResponseInterface $response): void
    {
        $body = $response->getBody();
        if ($body->isSeekable()) $body->rewind();

        // Só um corpo de tamanho desconhecido é gerado enquanto é enviado, e só
        // ele ganha algo com o flush. Esvaziar o buffer de um corpo de tamanho
        // fixo não adianta latência nenhuma e atropela quem envolveu a emissão
        // no próprio buffer.
        $streaming = $body->getSize() === null;

        while (!$body->eof()) {
            $chunk = $body->read(self::CHUNK);
            if ($chunk === '') break;

            echo $chunk;
            if ($streaming) {
                if (ob_get_level() > 0) ob_flush();
                flush();
            }
        }
    }

    private static function isBodyless(int $status): bool
    {
        return $status === 204 || $status === 304 || ($status >= 100 && $status < 200);
    }
}
