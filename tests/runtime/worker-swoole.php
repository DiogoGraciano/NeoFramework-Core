<?php

declare(strict_types=1);

/** Servidor Swoole da aplicação-sonda, com um worker sequencial. */
require __DIR__ . '/../../vendor/autoload.php';

use NeoFramework\Core\Application;
use NeoFramework\Core\Kernel;
use NeoFramework\Core\Request;
use NeoFramework\Core\Session;
use NeoFramework\Core\Support\ProjectRoot;

ProjectRoot::set(__DIR__);
Kernel::loadEnv();

$application = (new Application())->boot();
$server = new Swoole\Http\Server('0.0.0.0', 8082);
// A extensão suporta corrotinas, mas os adaptadores legados (sessão PHP e APIs
// baseadas em superglobais) são sequenciais. Um worker, sem corrotina, reproduz
// o contrato dos outros runtimes e impede interleaving de $_SESSION/$_COOKIE.
$server->set(['worker_num' => 1, 'enable_coroutine' => false, 'log_file' => '/dev/stderr']);

$server->on('request', static function (Swoole\Http\Request $native, Swoole\Http\Response $out) use ($application): void {
    $server = $native->server ?? [];
    $headers = $native->header ?? [];
    $cookies = $native->cookie ?? [];
    $query = $native->get ?? [];
    $method = (string) ($server['request_method'] ?? 'GET');
    $target = (string) ($server['request_uri'] ?? '/');
    if (($server['query_string'] ?? '') !== '') $target .= '?' . $server['query_string'];
    $host = (string) ($headers['host'] ?? 'swoole');

    $_SERVER = [
        'REQUEST_METHOD' => $method,
        'REQUEST_URI' => $target,
        'SERVER_PROTOCOL' => 'HTTP/1.1',
        'HTTP_HOST' => $host,
    ];
    $_COOKIE = $cookies;
    $sessionName = session_name();
    $receivedSession = (string) ($cookies[$sessionName] ?? '');
    if (preg_match('/^[A-Za-z0-9,-]{22,256}$/', $receivedSession) === 1) session_id($receivedSession);

    try {
        Session::start();
        $request = (new Request($method, 'http://' . $host . $target, $headers, $native->rawContent(), '1.1', $_SERVER))
            ->withCookieParams($cookies)
            ->withQueryParams($query)
            ->withParsedBody($native->post ?? null);
        $response = $application->handle($request);

        $out->status($response->getStatusCode());
        foreach ($response->getHeaders() as $name => $values) $out->header($name, implode(', ', $values));

        $sessionId = session_id();
        if ($sessionId !== '' && $sessionId !== $receivedSession) {
            $out->header('Set-Cookie', $sessionName . '=' . rawurlencode($sessionId) . '; Path=/; HttpOnly; SameSite=Lax');
        }

        $body = $response->getBody();
        if ($body->isSeekable()) $body->rewind();
        $out->end((string) $body);
    } catch (Throwable $error) {
        $out->status(500);
        $out->header('Content-Type', 'application/json');
        $out->end(json_encode(['error' => 'runtime failure', 'type' => $error::class], JSON_THROW_ON_ERROR));
    } finally {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        session_id('');
        $_SESSION = [];
        $_COOKIE = [];
        $_SERVER = [];
    }
});

$server->start();
