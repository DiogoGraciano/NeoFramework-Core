<?php
declare(strict_types=1);

namespace NeoFramework\Core\Commands\Auth;

use Ahc\Cli\Input\Command;
use NeoFramework\Core\Auth\BearerTokenGuard;
use NeoFramework\Core\Auth\UserProviderInterface;
use NeoFramework\Core\Container;

/** Emite um token somente quando a aplicação configurou provider e repositório durável. */
final class Token extends Command
{
    public function __construct()
    {
        parent::__construct('auth:token', 'Issue an opaque bearer token for an identity');
        $this->argument('<identity>', 'Stable identity identifier')
            ->option('-e --expires [minutes]', 'Lifetime in minutes (default: 60)')
            ->option('-s --scope [scopes...]', 'Token scopes');
    }

    public function execute(string $identity, ?int $expires, ?array $scope): int
    {
        $container = new Container();
        $user = $container->get(UserProviderInterface::class)->findById($identity);
        if ($user === null) {
            fwrite(STDERR, "Identity not found.\n");
            return 1;
        }
        $minutes = $expires ?? 60;
        if ($minutes < 1) {
            fwrite(STDERR, "--expires must be at least one minute.\n");
            return 1;
        }
        $guard = $container->get(BearerTokenGuard::class);
        $issued = $guard->issue($user, new \DateTimeImmutable("+{$minutes} minutes"), $scope ?? []);
        echo $issued['token'] . PHP_EOL;

        return 0;
    }
}
