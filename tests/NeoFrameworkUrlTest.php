<?php

namespace Tests;

use InvalidArgumentException;
use NeoFramework\Core\Response;
use NeoFramework\Core\Url;
use PHPUnit\Framework\TestCase;

/**
 * Regressão de host header injection e open redirect.
 *
 * getUrlBase() usava HTTP_HOST sem qualquer validação, então um Host forjado
 * contaminava todo link gerado — inclusive os de recuperação de senha.
 */
class NeoFrameworkUrlTest extends TestCase
{
    private array $serverBackup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;

        unset($_ENV['APP_URL'], $_ENV['TRUSTED_HOSTS'], $_ENV['TRUSTED_PROXIES']);
        unset($_SERVER['APP_URL'], $_SERVER['TRUSTED_HOSTS'], $_SERVER['TRUSTED_PROXIES']);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        unset($_ENV['APP_URL'], $_ENV['TRUSTED_HOSTS'], $_ENV['TRUSTED_PROXIES']);

        parent::tearDown();
    }

    public function testAppUrlTemPrecedenciaSobreOHostDaRequisicao()
    {
        $_ENV['APP_URL'] = 'https://app.exemplo.com';
        $_SERVER['HTTP_HOST'] = 'atacante.com';

        $this->assertEquals('https://app.exemplo.com/', Url::getUrlBase());
    }

    public function testHostForaDaAllowlistNaoEhUsado()
    {
        $_ENV['TRUSTED_HOSTS'] = 'app.exemplo.com';
        $_SERVER['HTTP_HOST'] = 'atacante.com';
        $_SERVER['SERVER_NAME'] = 'app.exemplo.com';

        $this->assertStringNotContainsString('atacante.com', Url::getUrlBase());
    }

    public function testHostNaAllowlistEhAceito()
    {
        $_ENV['TRUSTED_HOSTS'] = 'app.exemplo.com,admin.exemplo.com';
        $_SERVER['HTTP_HOST'] = 'admin.exemplo.com';

        $this->assertEquals('http://admin.exemplo.com/', Url::getUrlBase());
    }

    public function testHostComCaracteresInvalidosEhRecusado()
    {
        $_SERVER['HTTP_HOST'] = "exemplo.com\r\nX-Injetado: 1";
        $_SERVER['SERVER_NAME'] = 'exemplo.com';

        $this->assertEquals('http://exemplo.com/', Url::getUrlBase());
    }

    public function testProxyNaoConfiavelNaoDefineHttps()
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT']);

        $this->assertFalse(Url::isSecure());
    }

    public function testProxyConfiavelDefineHttps()
    {
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.1';
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT']);

        $this->assertTrue(Url::isSecure());
    }

    public function testGoRecusaUrlAbsoluta()
    {
        $this->expectException(InvalidArgumentException::class);

        (new Response())->go('https://atacante.com');
    }

    public function testGoRecusaBarraDupla()
    {
        // "//atacante.com" é tratado pelo navegador como URL absoluta.
        $this->expectException(InvalidArgumentException::class);

        (new Response())->go('//atacante.com');
    }

    public function testGoToSiteRecusaEsquemaPerigoso()
    {
        $this->expectException(InvalidArgumentException::class);

        (new Response())->goToSite('javascript:alert(1)');
    }

    public function testGoToSiteAceitaHttps()
    {
        $response = (new Response())->goToSite('https://parceiro.com/callback');

        $this->assertEquals(['https://parceiro.com/callback'], $response->getHeader('Location'));
    }
}
