<?php
declare(strict_types=1);

namespace Tests;

use NeoFramework\Core\Request;
use NeoFramework\Core\Session;
use PHPUnit\Framework\TestCase;

/**
 * Regressão da proteção de CSRF.
 *
 * Antes destes testes a validação nunca era executada e, quando era, rejeitava
 * justamente as requisições que traziam o token correto.
 */
class NeoFrameworkCsrfTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);

        parent::tearDown();
    }

    /**
     * A sessão de teste roda em CLI, onde session_start() não está disponível
     * de forma confiável; o estado é montado direto no superglobal.
     */
    private function withSessionToken(string $token): void
    {
        $_SESSION['neof_CSRF_TOKEN'] = $token;
    }

    public function testTokenAusenteNaoValida()
    {
        $this->withSessionToken('token-esperado');

        $this->assertFalse(Session::validateCsrfToken(null));
        $this->assertFalse(Session::validateCsrfToken(''));
    }

    public function testTokenDiferenteNaoValida()
    {
        $this->withSessionToken('token-esperado');

        $this->assertFalse(Session::validateCsrfToken('token-do-atacante'));
    }

    public function testTokenCorretoValida()
    {
        $this->withSessionToken('token-esperado');

        // O bug original devolvia 403 exatamente neste caso.
        $this->assertTrue(Session::validateCsrfToken('token-esperado'));
    }

    public function testSessaoSemTokenNuncaValida()
    {
        $_SESSION = [];

        $this->assertFalse(Session::validateCsrfToken('qualquer-coisa'));
        $this->assertFalse(Session::validateCsrfToken(''));
    }

    public function testTokenEhLidoDoCabecalhoHttp()
    {
        // A implementação anterior procurava $_SERVER['X-CSRF-TOKEN'], chave que
        // o PHP nunca cria: cabeçalhos chegam com o prefixo HTTP_.
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token-do-cabecalho';

        $request = Request::fromGlobals();

        $this->assertEquals('token-do-cabecalho', $request->getCsrfToken());
    }

    public function testCorpoTemPrecedenciaSobreCabecalho()
    {
        $_POST['CSRF_TOKEN'] = 'token-do-corpo';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token-do-cabecalho';

        $request = Request::fromGlobals();

        $this->assertEquals('token-do-corpo', $request->getCsrfToken());
    }

    public function testControllerNaoPulaValidacaoPorPadrao()
    {
        $this->assertFalse(\NeoFramework\Core\Abstract\Controller::skipCsrfValidation);
    }

    public function testRotaComGetEPostContinuaExigindoCsrf()
    {
        // O atributo desligava o CSRF da rota inteira quando ela também aceitava
        // GET, o que deixava o POST desprotegido.
        $route = new \NeoFramework\Core\Attributes\Route('/salvar', ['GET', 'POST']);

        $this->assertTrue($route->getValidCsrf());
    }

    public function testRotaPodeOptarPorNaoValidar()
    {
        $route = new \NeoFramework\Core\Attributes\Route('/webhook', ['POST'], false);

        $this->assertFalse($route->getValidCsrf());
    }
}
