<?php
declare(strict_types=1);
namespace NeoFramework\Core\Http;

/** Static bridge for legacy static helpers; HttpKernel always clears it in finally. */
final class RequestScopeContext {
    private static ?RequestScopeInterface $current = null;
    /** @var array<int,RequestScopeInterface> */
    private static array $coroutines = [];

    public static function enter(RequestScopeInterface $scope): void
    {
        $coroutine = self::coroutineId();
        if ($coroutine !== null) {
            self::$coroutines[$coroutine] = $scope;

            return;
        }

        self::$current = $scope;
    }

    public static function current(): ?RequestScopeInterface
    {
        $coroutine = self::coroutineId();

        return $coroutine === null ? self::$current : self::$coroutines[$coroutine] ?? null;
    }

    public static function leave(RequestScopeInterface $scope): void
    {
        $coroutine = self::coroutineId();
        if ($coroutine !== null) {
            if ((self::$coroutines[$coroutine] ?? null) === $scope) unset(self::$coroutines[$coroutine]);

            return;
        }

        if (self::$current === $scope) self::$current = null;
    }

    /** Swoole não é dependência do Core; a consulta dinâmica mantém-o opcional. */
    private static function coroutineId(): ?int
    {
        if (!extension_loaded('swoole') || !class_exists('Swoole\\Coroutine', false)) return null;

        $id = call_user_func(['Swoole\\Coroutine', 'getCid']);

        return is_int($id) && $id >= 0 ? $id : null;
    }
}
