<?php
declare(strict_types=1);

namespace NeoFramework\Core\Storage;

/** Não inspeciona nada. É o padrão: o Core não embute antivírus. */
final class NullUploadScanner implements UploadScannerInterface
{
    public function scan(string $temporaryPath, string $mimeType): void
    {
    }
}
