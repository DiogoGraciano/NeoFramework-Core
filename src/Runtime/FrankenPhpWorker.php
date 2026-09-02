<?php
declare(strict_types=1);

namespace NeoFramework\Core\Runtime;

use NeoFramework\Core\Application;
use NeoFramework\Core\Http\ResponseEmitter;
use NeoFramework\Core\Request;
use NeoFramework\Core\Session;

/** Adapter opcional para o loop nativo de worker do FrankenPHP. */
final readonly class FrankenPhpWorker
{
    public function __construct(private Application $application, private ResponseEmitter $emitter = new ResponseEmitter()) {}

    /**
     * Processa no máximo $maxRequests (zero significa sem limite).
     *
     * O callback é intencionalmente pequeno: o FrankenPHP recompõe as
     * superglobais antes de cada chamada e o Application encerra seu ciclo no
     * finally, inclusive quando uma action lança uma exceção.
     */
    public function run(int $maxRequests = 0): void
    {
        if ($maxRequests < 0) throw new \InvalidArgumentException('maxRequests cannot be negative.');
        if (!function_exists('frankenphp_handle_request')) {
            throw new \LogicException('The FrankenPHP worker API is unavailable. Run this script under FrankenPHP worker mode.');
        }

        $handler = function (): void {
            try {
                // A sessão não pode permanecer aberta na thread entre callbacks:
                // além de reter dados, ela manteria o lock do usuário anterior.
                Session::start();
                $response = $this->application->handle(Request::fromGlobals());
                try {
                    $this->emitter->emit($response);
                } finally {
                    $response->getBody()->close();
                }
            } catch (\Throwable $error) {
                // `set_exception_handler` só roda quando o script worker acaba.
                // Tratar aqui mantém a thread disponível para a próxima chamada.
                error_log('NeoFramework worker request failed: ' . $error->getMessage());
                if (!headers_sent()) {
                    http_response_code(500);
                    header('Content-Type: text/plain; charset=utf-8');
                }
                echo 'Internal Server Error';
            } finally {
                if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            }
        };

        for ($handled = 0; $maxRequests === 0 || $handled < $maxRequests; $handled++) {
            $keepRunning = call_user_func('frankenphp_handle_request', $handler);
            gc_collect_cycles();
            if ($keepRunning !== true) break;
        }
    }
}
