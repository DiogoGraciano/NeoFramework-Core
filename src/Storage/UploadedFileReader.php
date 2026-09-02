<?php
declare(strict_types=1);

namespace NeoFramework\Core\Storage;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Traz um upload PSR-7 para um arquivo temporário local, em blocos.
 *
 * O arquivo local existe por duas razões: detectar o MIME exige bytes no disco,
 * e o limite de tamanho precisa ser aplicado **enquanto** se lê. Confiar em
 * `getSize()` — que vem do cliente — deixaria um upload de qualquer tamanho
 * encher o disco antes da primeira verificação.
 */
final class UploadedFileReader
{
    private const CHUNK = 8192;

    /**
     * @return array{path:string,size:int}
     * @throws UploadRejectedException quando o conteúdo passa de $maxBytes
     */
    public static function toTemporaryFile(StreamInterface $stream, int $maxBytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'neof_upload_');
        if ($path === false) throw new RuntimeException('Unable to create a temporary file for the upload.');

        $handle = fopen($path, 'w+b');
        if ($handle === false) {
            @unlink($path);
            throw new RuntimeException('Unable to open the temporary upload file.');
        }

        try {
            $size = self::copy($stream, $handle, $maxBytes);
        } catch (\Throwable $error) {
            // Um upload recusado no meio da cópia não deixa resto no /tmp.
            fclose($handle);
            @unlink($path);
            throw $error;
        }

        fclose($handle);

        return ['path' => $path, 'size' => $size];
    }

    /** @param resource $destination */
    private static function copy(StreamInterface $stream, $destination, int $maxBytes): int
    {
        if ($stream->isSeekable()) $stream->rewind();

        $size = 0;
        while (!$stream->eof()) {
            $chunk = $stream->read(self::CHUNK);
            if ($chunk === '') break;

            $size += strlen($chunk);
            // Para de ler no primeiro byte excedente: o custo de recusar não
            // pode ser proporcional ao que o cliente decidiu enviar.
            if ($size > $maxBytes) throw new UploadRejectedException('File size is not allowed');

            if (fwrite($destination, $chunk) === false) throw new RuntimeException('Unable to write the temporary upload file.');
        }

        return $size;
    }
}
