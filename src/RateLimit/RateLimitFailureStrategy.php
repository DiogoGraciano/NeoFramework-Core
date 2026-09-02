<?php
declare(strict_types=1);

namespace NeoFramework\Core\RateLimit;

/** O que fazer quando o backend do rate limit está indisponível. */
enum RateLimitFailureStrategy: string
{
    /** Deixa a requisição passar. Prioriza disponibilidade. */
    case Open = 'open';

    /** Recusa a requisição com 429. Prioriza a proteção do recurso limitado. */
    case Closed = 'closed';
}
