<?php
declare(strict_types=1);

namespace NeoFramework\Core\Runtime;

/** Reporter seguro para worker: nunca deixa uma falha de telemetria derrubar o processo. */
final class ErrorLogRuntimeReporter implements RuntimeReporterInterface
{
    public function resetFailed(string $service, \Throwable $error): void
    {
        error_log(sprintf('NeoFramework runtime could not reset %s: %s', $service, $error->getMessage()));
    }
}
