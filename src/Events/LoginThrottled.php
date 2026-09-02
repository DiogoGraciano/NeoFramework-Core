<?php

declare(strict_types=1);

namespace NeoFramework\Core\Events;

/**
 * Uma tentativa de login foi recusada antes de verificar a credencial.
 *
 * `dimension` diz qual contador estourou: `identifier` indica ataque dirigido a
 * uma conta, `ip` indica uma origem varrendo várias. Os dois pedem respostas
 * diferentes, e um evento que não os separasse esconderia essa diferença.
 */
final readonly class LoginThrottled
{
    public function __construct(
        public string $identifier,
        public string $ip,
        public string $dimension,
        public int $retryAfter,
    ) {
    }
}
