<?php

declare(strict_types=1);

namespace NeoFramework\Core\Jobs;

use NeoFramework\Core\Events\Events;
use NeoFramework\Core\Http\RequestScope;
use NeoFramework\Core\Http\RequestScopeContext;
use NeoFramework\Core\Http\RequestScopeInterface;
use NeoFramework\Core\Jobs\Entity\JobEntity;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Escopo descartável por job, equivalente ao que a Fase 3 deu ao HTTP.
 *
 * `queue:work` é um processo que roda indefinidamente, e até aqui cada job
 * herdava o que o anterior tivesse deixado no escopo estático — a mesma classe
 * de vazamento que o `RequestScope` existe para impedir entre requisições.
 * Além disso, sem escopo o `Events::dispatch()` era um no-op silencioso no
 * worker, o `Logger` não tinha contexto e `AuthContext::set()` gravava no vazio.
 */
final class JobScope
{
    /**
     * Executa `$work` dentro de um escopo próprio.
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    public static function run(JobEntity $job, string $queue, ?EventDispatcherInterface $dispatcher, callable $work): mixed
    {
        // Um job despachado de dentro de uma requisição não pode apagar o escopo
        // dela: `leave()` só zera, então o anterior é restaurado à mão.
        $previous = RequestScopeContext::current();

        $scope = new RequestScope();
        RequestScopeContext::enter($scope);

        if ($dispatcher !== null) $scope->set(Events::KEY, $dispatcher);
        $scope->set('neoframework.log_context', [
            'jobId' => $job->getId(),
            'job' => $job->getClass(),
            'queue' => $queue,
        ]);

        try {
            return $work();
        } finally {
            $scope->reset();
            RequestScopeContext::leave($scope);
            if ($previous instanceof RequestScopeInterface) RequestScopeContext::enter($previous);
        }
    }
}
