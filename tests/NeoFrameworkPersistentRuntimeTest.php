<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Os critérios de aceite da §18 medidos no processo real.
 *
 * Teste unitário não alcança isto: o que se verifica aqui é o comportamento do
 * worker ENTRE requisições — memória que não estabiliza, escopo que não é
 * descartado, exceção que envenena o processo. Nada disso aparece numa chamada
 * isolada a `Application::handle()`.
 */
#[Group('runtime')]
final class NeoFrameworkPersistentRuntimeTest extends TestCase
{
    /** @return array<string,array{string}> */
    public static function runtimes(): array
    {
        return [
            'frankenphp' => ['FRANKENPHP_URL'],
            'roadrunner' => ['ROADRUNNER_URL'],
            'swoole' => ['SWOOLE_URL'],
            'php-fpm' => ['FPM_URL'],
        ];
    }

    private function baseUrl(string $variable): string
    {
        $url = (string) (getenv($variable) ?: '');
        if ($url === '') self::markTestSkipped("{$variable} não está configurada.");

        return rtrim($url, '/');
    }

    /** @return array{status:int,body:array<string,mixed>,headers:array<string,string>} */
    private function get(string $base, string $path, array $cookies = []): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => $cookies === [] ? '' : 'Cookie: ' . http_build_query($cookies, '', '; ') . "\r\n",
            'ignore_errors' => true,
            'timeout' => 10,
        ]]);

        $body = file_get_contents($base . $path, false, $context);
        $status = 0;
        $headers = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m) === 1) { $status = (int) $m[1]; continue; }
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        return ['status' => $status, 'body' => json_decode((string) $body, true) ?: [], 'headers' => $headers];
    }

    /** Request A nunca observa dados do request B — agora no processo de verdade. */
    #[DataProvider('runtimes')]
    public function testAThousandAlternatingRequestsNeverCrossTalk(string $variable): void
    {
        $base = $this->baseUrl($variable);

        for ($i = 0; $i < 1000; $i++) {
            $expected = 'valor-' . $i;
            $response = $this->get($base, '/probe/echo/' . $expected);

            self::assertSame(200, $response['status']);
            self::assertSame($expected, $response['body']['value'] ?? null, "A requisição {$i} recebeu a resposta de outra.");
        }
    }

    /** O processo precisa ser determinístico; FPM reseta estáticos por request. */
    #[DataProvider('runtimes')]
    public function testRequestsShareOneProcess(string $variable): void
    {
        $base = $this->baseUrl($variable);
        $pids = [];

        for ($i = 0; $i < 20; $i++) $pids[] = $this->get($base, '/probe/echo/x')['body']['pid'] ?? null;

        self::assertCount(1, array_unique($pids), 'As requisições caíram em processos diferentes; o teste de isolamento seria inconclusivo.');
        $requests = $this->get($base, '/probe/echo/x')['body']['processRequests'] ?? null;
        if ($variable === 'FPM_URL') {
            // O FPM reaproveita o filho, mas o SAPI zera estado PHP entre
            // requests; este é justamente o baseline que os workers devem
            // reproduzir explicitamente com RequestScope e reset().
            self::assertSame(1, $requests);

            return;
        }

        self::assertGreaterThan(1, $requests, 'O contador estático não sobreviveu: o processo está sendo reciclado a cada requisição.');
    }

    /** A identidade gravada no escopo de uma requisição não pode vazar. */
    #[DataProvider('runtimes')]
    public function testTheAuthenticatedIdentityDoesNotLeakToTheNextRequest(string $variable): void
    {
        $base = $this->baseUrl($variable);

        self::assertSame('usuario-1', $this->get($base, '/probe/identify/usuario-1')['body']['identity'] ?? null);
        self::assertNull($this->get($base, '/probe/whoami')['body']['identity'] ?? null, 'A requisição seguinte enxergou a identidade da anterior.');

        self::assertSame('usuario-2', $this->get($base, '/probe/identify/usuario-2')['body']['identity'] ?? null);
        self::assertNull($this->get($base, '/probe/whoami')['body']['identity'] ?? null);
    }

    /** Dois clientes no mesmo worker têm sessões independentes. */
    #[DataProvider('runtimes')]
    public function testSessionsAreIsolatedBetweenClients(string $variable): void
    {
        $base = $this->baseUrl($variable);

        $first = $this->get($base, '/probe/session');
        self::assertSame(1, $first['body']['count'] ?? null);
        $cookie = $this->sessionCookie($first['headers']);
        self::assertNotNull($cookie, 'O worker não emitiu cookie de sessão.');

        // O mesmo cliente avança o contador...
        self::assertSame(2, $this->get($base, '/probe/session', $cookie)['body']['count'] ?? null);
        // ...e um cliente sem cookie começa do zero.
        self::assertSame(1, $this->get($base, '/probe/session')['body']['count'] ?? null);
        // O primeiro continua de onde parou: nada foi sobrescrito no processo.
        self::assertSame(3, $this->get($base, '/probe/session', $cookie)['body']['count'] ?? null);
    }

    /** @param array<string,string> $headers @return array<string,string>|null */
    private function sessionCookie(array $headers): ?array
    {
        $setCookie = $headers['set-cookie'] ?? '';
        if (preg_match('~^([^=]+)=([^;]+)~', $setCookie, $match) !== 1) return null;

        return [$match[1] => $match[2]];
    }

    /** Uma exceção não pode derrubar o worker nem contaminar a próxima requisição. */
    #[DataProvider('runtimes')]
    public function testAnExceptionDoesNotPoisonTheWorker(string $variable): void
    {
        $base = $this->baseUrl($variable);

        for ($i = 0; $i < 25; $i++) {
            self::assertSame(500, $this->get($base, '/probe/boom')['status']);
            self::assertSame('depois-' . $i, $this->get($base, '/probe/echo/depois-' . $i)['body']['value'] ?? null);
        }

        self::assertNull($this->get($base, '/probe/whoami')['body']['identity'] ?? null);
    }

    /**
     * "Memória estabiliza após warmup" — o critério que só o processo real mede.
     *
     * A comparação é entre DOIS pontos depois do warmup, e não contra o início:
     * o crescimento das primeiras centenas de requisições é o bootstrap
     * preenchendo cache e opcache, e reprovar por causa dele seria ruído.
     */
    #[DataProvider('runtimes')]
    public function testMemoryStabilisesAfterWarmup(string $variable): void
    {
        $base = $this->baseUrl($variable);

        for ($i = 0; $i < 300; $i++) $this->get($base, '/probe/echo/aquecimento-' . $i);
        $afterWarmup = $this->get($base, '/probe/memory')['body']['bytes'] ?? 0;
        self::assertGreaterThan(0, $afterWarmup);

        for ($i = 0; $i < 700; $i++) $this->get($base, '/probe/echo/carga-' . $i);
        $afterLoad = $this->get($base, '/probe/memory')['body']['bytes'] ?? 0;

        $growth = ($afterLoad - $afterWarmup) / $afterWarmup;
        self::assertLessThan(
            0.10,
            $growth,
            sprintf('A memória cresceu %.1f%% em 700 requisições depois do warmup (%d → %d bytes).', $growth * 100, $afterWarmup, $afterLoad),
        );
    }
}
