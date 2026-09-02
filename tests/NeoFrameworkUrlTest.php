<?php
declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use NeoFramework\Core\Config;
use NeoFramework\Core\Config\ConfigRepository;
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

        $this->useConfig();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        Config::reset();

        parent::tearDown();
    }

    public function testAppUrlTemPrecedenciaSobreOHostDaRequisicao()
    {
        $this->useConfig(url: 'https://app.exemplo.com');
        $_SERVER['HTTP_HOST'] = 'atacante.com';

        $this->assertEquals('https://app.exemplo.com/', Url::getUrlBase());
    }

    public function testHostForaDaAllowlistNaoEhUsado()
    {
        $this->useConfig(hosts: ['app.exemplo.com']);
        $_SERVER['HTTP_HOST'] = 'atacante.com';
        $_SERVER['SERVER_NAME'] = 'app.exemplo.com';

        $this->assertStringNotContainsString('atacante.com', Url::getUrlBase());
    }

    public function testHostNaAllowlistEhAceito()
    {
        $this->useConfig(hosts: ['app.exemplo.com', 'admin.exemplo.com']);
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
        $this->useConfig(proxies: ['10.0.0.1']);
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

    /** @param list<string> $hosts @param list<string> $proxies */
    private function useConfig(string $url = '', array $hosts = [], array $proxies = []): void
    {
        Config::setRepository(new ConfigRepository(sys_get_temp_dir(), ['app' => ['environment' => 'test', 'url' => $url], 'http' => ['trusted_hosts' => $hosts, 'trusted_proxies' => $proxies]], false));
    }
}
