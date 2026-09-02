<?php
declare(strict_types=1);

namespace NeoFramework\Core;

use NeoFramework\Core\Http\ResettableInterface;
use NeoFramework\Core\Runtime\ErrorLogRuntimeReporter;
use NeoFramework\Core\Runtime\RuntimeReporterInterface;
use NeoFramework\Core\Validation\RespectValidator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Fachada segura para FPM e workers persistentes.
 *
 * O kernel já encerra o RequestScope em `finally`. Esta classe complementa o
 * ciclo liberando serviços compartilhados que declaram ResettableInterface
 * depois de cada handle, inclusive quando a aplicação lança uma exceção.
 */
final class Application
{
    private ?HttpKernel $kernel;
    private bool $booted = false;

    /** @var array<string,ResettableInterface> */
    private array $resettables = [];

    /**
     * @param iterable<string|int,ResettableInterface> $resettables
     */
    public function __construct(?HttpKernel $kernel = null, iterable $resettables = [], private readonly RuntimeReporterInterface $reporter = new ErrorLogRuntimeReporter())
    {
        $this->kernel = $kernel;
        foreach ($resettables as $name => $service) {
            $this->resettables[is_string($name) ? $name : $service::class] = $service;
        }
    }

    public function boot(): self
    {
        if (!$this->booted) {
            // Ficava em Kernel::init(), que só o caminho FPM percorre: sob
            // FrankenPHP ou RoadRunner as regras de banco não eram resolvíveis
            // pelo nome, e `#[Rule('uniqueDb')]` estourava só no worker.
            RespectValidator::registerCoreRules();
            $this->kernel ??= HttpKernel::create();
            $this->booted = true;
        }

        return $this;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->boot();

        try {
            return $this->kernel->handle($request);
        } finally {
            $this->terminate();
        }
    }

    /**
     * Executa a limpeza por requisição. Falhas são reportadas, mas nunca
     * impedem a próxima requisição de ser atendida pelo worker.
     */
    public function terminate(): void
    {
        foreach ($this->resettables as $name => $service) {
            try {
                $service->reset();
            } catch (\Throwable $error) {
                try {
                    $this->reporter->resetFailed($name, $error);
                } catch (\Throwable) {
                    // O reporter é observabilidade; não pode quebrar o worker.
                }
            }
        }
    }
}
