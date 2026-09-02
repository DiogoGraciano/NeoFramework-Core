<?php
declare(strict_types=1);

namespace NeoFramework\RoadRunner;

use NeoFramework\Core\Application;

/** Executa o Application sem permitir que uma falha encerre o processo filho. */
final class RoadRunnerWorker
{
    public function __construct(private readonly SessionBridge $sessions = new SessionBridge())
    {
    }

    public function run(Application $application, RoadRunnerHttpWorkerInterface $worker, int $maxRequests = 0): void
    {
        if ($maxRequests < 0) throw new \InvalidArgumentException('maxRequests cannot be negative.');

        for ($handled = 0; $maxRequests === 0 || $handled < $maxRequests;) {
            try {
                $request = $worker->waitRequest();
            } catch (\Throwable $error) {
                $this->report($worker, $error);
                continue;
            }

            if ($request === null) break;
            $response = null;

            try {
                // A sessão abre antes do handle e fecha depois: o RoadRunner não
                // recompõe `$_COOKIE` nem coleta o `Set-Cookie` de `header()`.
                $this->sessions->open($request);
                $response = $this->sessions->close($application->handle($request));
                $worker->respond($response);
            } catch (\Throwable $error) {
                $this->report($worker, $error);
            } finally {
                // Mesmo numa falha a sessão precisa ser fechada e zerada, ou o
                // próximo request herda o id de quem estourou.
                $this->closeQuietly();
                $response?->getBody()->close();
                gc_collect_cycles();
            }

            $handled++;
        }

        if ($maxRequests !== 0) $worker->stop();
    }

    private function closeQuietly(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) return;

        // Não lança: uma falha aqui vira warning e a limpeza abaixo acontece de
        // qualquer jeito, que é o que impede o próximo request de herdar o id.
        session_write_close();

        $_SESSION = [];
        $_COOKIE = [];
        session_id('');
    }

    private function report(RoadRunnerHttpWorkerInterface $worker, \Throwable $error): void
    {
        try {
            $worker->report($error);
        } catch (\Throwable $reportingError) {
            error_log('NeoFramework RoadRunner worker could not report an error: ' . $reportingError->getMessage());
        }
    }
}
