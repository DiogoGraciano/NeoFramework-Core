<?php
declare(strict_types=1);

namespace NeoFramework\Core\Runtime;

interface RuntimeReporterInterface
{
    public function resetFailed(string $service, \Throwable $error): void;
}
