<?php

namespace Tests;

use InvalidArgumentException;
use NeoFramework\Core\Middleware\Cors;
use NeoFramework\Core\Request;
use NeoFramework\Core\Response;
use PHPUnit\Framework\TestCase;

/**
 * Regressão do middleware de CORS.
 *
 * A configuração padrão refletia a Origin do requisitante quando credenciais
 * estavam habilitadas, o que permite a qualquer site ler respostas autenticadas.
 */
class NeoFrameworkCorsTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_ORIGIN'], $_SERVER['REQUEST_METHOD']);
        parent::tearDown();
    }

    private function requestFromOrigin(?string $origin): Request
    {
        unset($_SERVER['HTTP_ORIGIN']);

        if ($origin !== null) {
            $_SERVER['HTTP_ORIGIN'] = $origin;
        }

        $_SERVER['REQUEST_METHOD'] = 'GET';

        return new Request();
    }

    private function allowOriginHeader(Cors $cors, Request $request): ?array
    {
        $response = new Response();

        $controller = $this->createMock(\NeoFramework\Core\Abstract\Controller::class);
        $controller->method('getRequest')->willReturn($request);
        $controller->method('getResponse')->willReturn($response);

        $cors->before($controller);

        return $response->getHeader('Access-Control-Allow-Origin');
    }

    public function testCredenciaisComCoringaEhRecusado()
    {
        $this->expectException(InvalidArgumentException::class);

        new Cors([
            'allowed_origins' => ['*'],
            'allow_credentials' => true,
        ]);
    }

    public function testOrigemForaDaListaNaoRecebeCabecalho()
    {
        $cors = new Cors(['allowed_origins' => ['https://app.exemplo.com']]);

        $header = $this->allowOriginHeader($cors, $this->requestFromOrigin('https://atacante.com'));

        $this->assertNull($header);
    }

    public function testOrigemNaListaRecebeCabecalho()
    {
        $cors = new Cors(['allowed_origins' => ['https://app.exemplo.com']]);

        $header = $this->allowOriginHeader($cors, $this->requestFromOrigin('https://app.exemplo.com'));

        $this->assertEquals(['https://app.exemplo.com'], $header);
    }

    public function testComCredenciaisSoOrigemListadaEhRefletida()
    {
        $cors = new Cors([
            'allowed_origins' => ['https://app.exemplo.com'],
            'allow_credentials' => true,
        ]);

        $this->assertNull($this->allowOriginHeader($cors, $this->requestFromOrigin('https://atacante.com')));
        $this->assertEquals(
            ['https://app.exemplo.com'],
            $this->allowOriginHeader($cors, $this->requestFromOrigin('https://app.exemplo.com'))
        );
    }

    public function testRequisicaoSemOriginNaoRecebeCabecalho()
    {
        $cors = new Cors();

        $this->assertNull($this->allowOriginHeader($cors, $this->requestFromOrigin(null)));
    }

    public function testCoringaSemCredenciaisEmiteAsterisco()
    {
        $cors = new Cors(['allowed_origins' => ['*']]);

        $this->assertEquals(['*'], $this->allowOriginHeader($cors, $this->requestFromOrigin('https://qualquer.com')));
    }
}
