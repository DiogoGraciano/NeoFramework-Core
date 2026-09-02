<?php
declare(strict_types=1);

namespace Tests;

use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Attributes\Route;
use NeoFramework\Core\Config;
use NeoFramework\Core\Config\ConfigRepository;
use NeoFramework\Core\Config\ConfigurationException;
use NeoFramework\Core\Config\LoggingConfig;
use NeoFramework\Core\Response;
use NeoFramework\Core\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class ObservabilityControllerFixture extends Controller
{
    #[Route('/trace', ['GET'], false, 'obs.trace')]
    public function trace(): Response { return $this->json(['ok' => true]); }

    #[Route('/fail', ['GET'], false, 'obs.fail')]
    public function fail(): Response { throw new \RuntimeException('erro'); }
}

final class NeoFrameworkObservabilityTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::setRepository(null);
        unset($_SERVER['REMOTE_ADDR']);
    }

    /** Declara o proxy como confiável via configuração, que é onde Url lê. */
    private function trustProxy(string $ip): void
    {
        Config::setRepository(new ConfigRepository('/tmp', ['http' => ['trusted_proxies' => [$ip]]]));
        $_SERVER['REMOTE_ADDR'] = $ip;
    }

    private function client(): TestClient
    {
        return TestClient::forControllers([ObservabilityControllerFixture::class]);
    }

    /** Sem o ID na resposta não há como ligar o que o usuário viu ao log. */
    public function testEveryResponseCarriesARequestId(): void
    {
        $response = $this->client()->get('/trace')->assertOk();

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $response->header('X-Request-Id'));
    }

    public function testErrorResponsesCarryTheRequestIdToo(): void
    {
        $response = $this->client()->getJson('/fail')->assertServerError();

        self::assertNotSame('', $response->header('X-Request-Id'));
        self::assertSame($response->header('X-Request-Id'), $response->json('requestId'), 'o ID do corpo e do header precisam ser o mesmo');
    }

    public function testEachRequestGetsADistinctId(): void
    {
        $first = $this->client()->get('/trace')->header('X-Request-Id');
        $second = $this->client()->get('/trace')->header('X-Request-Id');

        self::assertNotSame($first, $second);
    }

    /** O ID do proxy só é aceito quando o proxy é confiável. */
    public function testForwardedRequestIdIsIgnoredFromAnUntrustedProxy(): void
    {
        $response = $this->client()->get('/trace', ['X-Request-Id' => 'id-do-balanceador']);

        self::assertNotSame('id-do-balanceador', $response->header('X-Request-Id'));
    }

    public function testForwardedRequestIdIsHonouredFromATrustedProxy(): void
    {
        $this->trustProxy('10.0.0.1');

        $response = $this->client()->get('/trace', ['X-Request-Id' => 'id-do-balanceador']);

        self::assertSame('id-do-balanceador', $response->header('X-Request-Id'));
    }

    /**
     * Valor fora do formato é descartado mesmo vindo de proxy confiável.
     *
     * CRLF já é recusado pelo próprio PSR-7 na construção da mensagem; o que
     * sobra para esta checagem é o valor sintaticamente válido porém arbitrário
     * — comprimento sem limite ou caracteres que poluem o log.
     */
    public function testMalformedForwardedRequestIdIsRejectedEvenFromATrustedProxy(): void
    {
        $this->trustProxy('10.0.0.1');

        foreach ([str_repeat('a', 200), 'id com espaço', 'id;com=lixo'] as $forged) {
            $response = $this->client()->get('/trace', ['X-Request-Id' => $forged]);
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $response->header('X-Request-Id'), "aceitou \"{$forged}\"");
        }
    }

    public function testLoggingConfigAcceptsJsonAndStderr(): void
    {
        $config = LoggingConfig::from(new ConfigRepository('/tmp', [
            'logging' => ['channel' => 'http', 'level' => 'warning', 'format' => 'json', 'stream' => 'stderr', 'path' => 'Logs/x.log'],
        ]));

        self::assertSame('http', $config->channel);
        self::assertSame('warning', $config->level);
        self::assertSame('json', $config->format);
        self::assertSame('stderr', $config->stream);
    }

    public function testLoggingConfigRejectsUnknownFormat(): void
    {
        $this->expectException(ConfigurationException::class);
        LoggingConfig::from(new ConfigRepository('/tmp', ['logging' => ['format' => 'xml']]));
    }

    public function testLoggingConfigRejectsUnknownLevel(): void
    {
        $this->expectException(ConfigurationException::class);
        LoggingConfig::from(new ConfigRepository('/tmp', ['logging' => ['level' => 'loud']]));
    }

    public function testLoggingDefaultsAreTheFileLineCombination(): void
    {
        $config = LoggingConfig::from(new ConfigRepository('/tmp', []));

        self::assertSame('line', $config->format);
        self::assertSame('file', $config->stream);
        self::assertSame('debug', $config->level);
    }
}
