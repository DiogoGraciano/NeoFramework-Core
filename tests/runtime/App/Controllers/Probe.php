<?php

declare(strict_types=1);

namespace App\Controllers;

use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Attributes\Route;
use NeoFramework\Core\Attributes\RoutePrefix;
use NeoFramework\Core\Auth\AuthContext;
use NeoFramework\Core\Auth\IdentityInterface;
use NeoFramework\Core\Response;
use NeoFramework\Core\Session;

final class ProbeIdentity implements IdentityInterface
{
    public function __construct(private readonly string $id)
    {
    }

    public function id(): string
    {
        return $this->id;
    }
}

/**
 * A aplicação-sonda usada para validar os runtimes persistentes.
 *
 * Cada action existe para checar um critério de aceite da §18 do roadmap dentro
 * de um processo real — coisas que teste unitário não alcança, porque o que se
 * mede é o comportamento do processo entre requisições, não o de uma chamada.
 */
#[RoutePrefix('/probe')]
final class Probe extends Controller
{
    /** Estático de propósito: mede o que de fato sobrevive entre requisições. */
    private static int $processRequests = 0;

    #[Route('/echo/{value}', ['GET'], false, 'probe.echo')]
    public function echo(string $value): Response
    {
        self::$processRequests++;

        return $this->json(['value' => $value, 'pid' => getmypid(), 'processRequests' => self::$processRequests]);
    }

    /** O contador vive na sessão: dois clientes têm que ter contadores distintos. */
    #[Route('/session', ['GET'], false, 'probe.session')]
    public function session(): Response
    {
        $count = (int) (Session::get('probe.count') ?? 0) + 1;
        Session::set('probe.count', $count);

        return $this->json(['count' => $count]);
    }

    /** Grava uma identidade no escopo. A requisição seguinte não pode enxergá-la. */
    #[Route('/identify/{id}', ['GET'], false, 'probe.identify')]
    public function identify(string $id): Response
    {
        AuthContext::set(new ProbeIdentity($id), 'probe');

        return $this->json(['identity' => AuthContext::current()->identity?->id()]);
    }

    #[Route('/whoami', ['GET'], false, 'probe.whoami')]
    public function whoami(): Response
    {
        return $this->json(['identity' => AuthContext::current()->identity?->id()]);
    }

    /** Uma exceção não pode envenenar o worker nem pular a limpeza do escopo. */
    #[Route('/boom', ['GET'], false, 'probe.boom')]
    public function boom(): Response
    {
        throw new \RuntimeException('explosão proposital da sonda');
    }

    #[Route('/memory', ['GET'], false, 'probe.memory')]
    public function memory(): Response
    {
        return $this->json([
            'bytes' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'processRequests' => self::$processRequests,
        ]);
    }
}
