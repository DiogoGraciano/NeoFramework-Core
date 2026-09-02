<?php
declare(strict_types=1);

namespace Tests;

use GuzzleHttp\Psr7\Utils;
use NeoFramework\Image\GdImageProcessor;
use PHPUnit\Framework\TestCase;

final class NeoFrameworkImageProcessorTest extends TestCase
{
    private function png(int $width, int $height): \Psr\Http\Message\StreamInterface
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 10, 120, 200));
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return Utils::streamFor($bytes);
    }

    private function jpegWithOrientation(int $width, int $height, int $orientation): \Psr\Http\Message\StreamInterface
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 10, 120, 200));
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        // APP1 mínimo com TIFF little-endian e a tag IFD0 Orientation. Assim
        // o teste verifica a rotação sem depender de fixture binária opaca.
        $tiff = "II*\x00\x08\x00\x00\x00\x01\x00\x12\x01\x03\x00\x01\x00\x00\x00" . pack('v', $orientation) . "\x00\x00\x00\x00\x00\x00";
        $app1 = "\xFF\xE1" . pack('n', strlen($tiff) + 8) . "Exif\x00\x00" . $tiff;

        return Utils::streamFor(substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2));
    }

    /** @return array{0:int,1:int} */
    private function sizeOf(\Psr\Http\Message\StreamInterface $stream): array
    {
        $stream->rewind();
        $info = getimagesizefromstring($stream->getContents());
        self::assertIsArray($info);

        return [$info[0], $info[1]];
    }

    public function testALargeImageIsScaledDownPreservingAspectRatio(): void
    {
        $processed = (new GdImageProcessor())->process($this->png(800, 400), 200, 200);

        // 800x400 dentro de 200x200 vira 200x100: a proporção manda, não o limite.
        self::assertSame([200, 100], $this->sizeOf($processed));
    }

    public function testASmallImageKeepsItsDimensionsButIsStillReencoded(): void
    {
        $original = $this->png(50, 40);
        $originalBytes = (string) $original;

        $processed = (new GdImageProcessor())->process($original, 200, 200);

        self::assertSame([50, 40], $this->sizeOf($processed));
        // Reencodar é o que remove EXIF — que carrega geolocalização — e o que
        // garante que o arquivo servido é mesmo a imagem que se acredita ter.
        $processed->rewind();
        self::assertNotSame($originalBytes, $processed->getContents());
    }

    public function testTheOutputIsWebpRegardlessOfTheInputFormat(): void
    {
        $processed = (new GdImageProcessor())->process($this->png(100, 100), 200, 200);
        $processed->rewind();

        self::assertSame('image/webp', getimagesizefromstring($processed->getContents())['mime'] ?? null);
    }

    public function testContentThatIsNotAnImageIsRejectedWithATypedError(): void
    {
        // Um upload malformado é erro de entrada, não bug: devolver exceção
        // tipada é melhor que um warning e `false`.
        $this->expectException(\RuntimeException::class);
        (new GdImageProcessor())->process(Utils::streamFor('isto não é uma imagem'), 100, 100);
    }

    public function testNonPositiveDimensionsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new GdImageProcessor())->process($this->png(10, 10), 0, 100);
    }

    public function testItDeclaresTheFormatsItAccepts(): void
    {
        $processor = new GdImageProcessor();

        self::assertTrue($processor->supports('image/jpeg'));
        self::assertTrue($processor->supports('IMAGE/PNG'));
        self::assertFalse($processor->supports('application/pdf'));
        // SVG é XML: processá-lo com gd não faz sentido, e aceitá-lo aqui daria
        // a impressão de que o conteúdo foi sanitizado.
        self::assertFalse($processor->supports('image/svg+xml'));
    }

    public function testAVeryWideImageIsBoundedByItsWidth(): void
    {
        $processed = (new GdImageProcessor())->process($this->png(1000, 100), 300, 300);

        self::assertSame([300, 30], $this->sizeOf($processed));
    }

    public function testJpegExifOrientationIsAppliedBeforeScaling(): void
    {
        $processed = (new GdImageProcessor())->process($this->jpegWithOrientation(80, 40, 6), 200, 200);

        self::assertSame([40, 80], $this->sizeOf($processed));
    }
}
