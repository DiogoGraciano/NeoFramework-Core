<?php
declare(strict_types=1);

namespace NeoFramework\Core\Storage;

use InvalidArgumentException;

/**
 * O upload foi recusado por uma regra.
 *
 * A mensagem é destinada ao usuário, então nunca cita caminho de servidor nem
 * o conteúdo enviado. Estende `InvalidArgumentException` para não quebrar quem
 * já captura o tipo antigo.
 */
final class UploadRejectedException extends InvalidArgumentException
{
}
