<?php
declare(strict_types=1);

namespace NeoFramework\Core\Storage;

/**
 * Inspeção do conteúdo antes de ele chegar ao disco.
 *
 * Recebe o caminho de um arquivo temporário local — nunca o destino final —
 * para que um veredito negativo signifique que nada foi publicado. É o ponto de
 * entrada para ClamAV ou qualquer verificação que a aplicação exija.
 */
interface UploadScannerInterface
{
    /** @throws UploadRejectedException quando o conteúdo deve ser recusado */
    public function scan(string $temporaryPath, string $mimeType): void;
}
