<?php

declare(strict_types=1);

namespace NeoFramework\Image;

use GdImage;
use GuzzleHttp\Psr7\Utils;
use NeoFramework\Core\Storage\ImageProcessorInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Processador de imagem sobre a extensão `gd`.
 *
 * Sempre reencoda, mesmo quando a imagem já cabe no limite. Copiar o arquivo
 * original preservaria os metadados EXIF — que costumam trazer coordenadas de
 * onde a foto foi tirada — e manteria o que quer que esteja embutido no
 * arquivo além dos pixels.
 */
final readonly class GdImageProcessor implements ImageProcessorInterface
{
    private const SUPPORTED = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(private int $quality = 82)
    {
        if (!extension_loaded('gd')) throw new RuntimeException('A extensão gd é exigida pelo GdImageProcessor.');
    }

    public function supports(string $mimeType): bool
    {
        return in_array(strtolower($mimeType), self::SUPPORTED, true);
    }

    public function process(StreamInterface $source, int $maxWidth, int $maxHeight): StreamInterface
    {
        if ($maxWidth < 1 || $maxHeight < 1) throw new \InvalidArgumentException('As dimensões máximas devem ser positivas.');

        $source->rewind();
        // O GD decodifica a imagem inteira por natureza. O contrato continua
        // recebendo stream para não obrigar o chamador a conhecer esse detalhe
        // do adapter nem a manter uma segunda cópia em memória.
        $contents = $source->getContents();
        $image = @imagecreatefromstring($contents);
        // Uma string que não é imagem chega aqui como upload malformado, não
        // como bug: devolver erro tipado é melhor que um warning e `false`.
        if (!$image instanceof GdImage) throw new RuntimeException('O conteúdo enviado não é uma imagem reconhecível.');

        try {
            $image = $this->orient($image, $contents);
            [$width, $height] = [imagesx($image), imagesy($image)];
            $scale = min($maxWidth / $width, $maxHeight / $height, 1.0);
            $target = $scale < 1.0 ? $this->scaled($image, (int) max(1, round($width * $scale)), (int) max(1, round($height * $scale))) : $image;

            try {
                return $this->encode($target);
            } finally {
                if ($target !== $image) imagedestroy($target);
            }
        } finally {
            imagedestroy($image);
        }
    }

    private function scaled(GdImage $image, int $width, int $height): GdImage
    {
        $target = imagecreatetruecolor($width, $height);
        if (!$target instanceof GdImage) throw new RuntimeException('Não foi possível alocar a imagem redimensionada.');

        // Sem isto, um PNG com transparência ganha fundo preto ao ser copiado.
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled($target, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));

        return $target;
    }

    private function encode(GdImage $image): StreamInterface
    {
        $path = tempnam(sys_get_temp_dir(), 'neof-image-');
        if ($path === false) throw new RuntimeException('Não foi possível criar o arquivo temporário da imagem.');

        try {
            if (!imagewebp($image, $path, $this->quality)) throw new RuntimeException('Não foi possível codificar a imagem.');

            $stream = fopen($path, 'rb');
            if ($stream === false) throw new RuntimeException('Não foi possível abrir a imagem codificada.');

            // No Unix o descritor continua válido após unlink; assim o arquivo
            // temporário não fica acumulado enquanto a resposta é emitida.
            unlink($path);

            return Utils::streamFor($stream);
        } catch (\Throwable $exception) {
            if (is_file($path)) unlink($path);

            throw $exception;
        }
    }

    private function orient(GdImage $image, string $contents): GdImage
    {
        if (!function_exists('exif_read_data') || !str_starts_with($contents, "\xFF\xD8\xFF")) return $image;

        $path = tempnam(sys_get_temp_dir(), 'neof-exif-');
        if ($path === false) return $image;

        try {
            if (file_put_contents($path, $contents) === false) return $image;
            $orientation = @exif_read_data($path, 'IFD0')['Orientation'] ?? 1;
        } finally {
            if (is_file($path)) unlink($path);
        }

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        if (!$rotated instanceof GdImage || $rotated === $image) return $image;

        imagedestroy($image);

        return $rotated;
    }
}
