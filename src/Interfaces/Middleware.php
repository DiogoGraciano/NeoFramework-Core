<?php
declare(strict_types=1);

namespace NeoFramework\Core\Interfaces;

use Psr\Http\Server\MiddlewareInterface;

/** Alias de compatibilidade nominal; o contrato é integralmente PSR-15. */
interface Middleware extends MiddlewareInterface {}
