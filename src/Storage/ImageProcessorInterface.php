<?php
declare(strict_types=1);

namespace NeoFramework\Core\Storage;

use Psr\Http\Message\StreamInterface;

/**
 * Redimensiona e reescreve imagens enviadas.
 *
 * O contrato vive no Core, a implementação não: processar imagem exige `gd` ou
 * `imagick`, e uma aplicação que só aceita PDF não deve carregar essa
 * dependência. O Core declara a costura; quem sabe de pixel é o adapter.
 *
 * `process()` recebe e devolve stream porque um upload já chega como stream —
 * materializar em string desfaria o trabalho que a §16 fez para não segurar o
 * arquivo inteiro em memória.
 */
interface ImageProcessorInterface
{
    /**
     * Reescreve a imagem cabendo em `maxWidth` x `maxHeight`, preservando proporção.
     *
     * Uma imagem menor que o limite é **reescrita mesmo assim**: reencodar é o
     * que remove metadados EXIF — que carregam geolocalização — e o que garante
     * que o arquivo servido é realmente a imagem que se acredita ter.
     */
    public function process(StreamInterface $source, int $maxWidth, int $maxHeight): StreamInterface;

    /** Os MIME types que este processador aceita. */
    public function supports(string $mimeType): bool;
}
