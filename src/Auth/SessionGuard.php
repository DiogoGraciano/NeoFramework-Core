<?php
declare(strict_types=1);

namespace NeoFramework\Core\Auth;

use NeoFramework\Core\Events\Events;
use NeoFramework\Core\Events\UserAuthenticated;
use NeoFramework\Core\Events\UserLoggedOut;
use NeoFramework\Core\Session;
use Psr\Http\Message\ServerRequestInterface;

final readonly class SessionGuard implements GuardInterface, AuthenticatorInterface
{
    public function __construct(private UserProviderInterface $users, private string $guardName = 'session') {}

    public function name(): string
    {
        return $this->guardName;
    }

    public function authenticate(ServerRequestInterface $request): ?IdentityInterface
    {
        $id = Session::get('auth.' . $this->guardName);

        return is_string($id) && $id !== '' ? $this->users->findById($id) : null;
    }

    /** Rotaciona o identificador e o token CSRF para impedir fixação de sessão. */
    public function login(IdentityInterface $identity): void
    {
        Session::regenerateId();
        Session::set('auth.' . $this->guardName, $identity->id());
        Session::regenerateCsrfToken();
        AuthContext::set($identity, $this->guardName);
        Events::dispatch(new UserAuthenticated($identity, $this->guardName));
    }

    public function logout(): void
    {
        // A identidade é lida ANTES de invalidar: depois disso não há mais de
        // onde tirá-la, e um listener de auditoria precisa saber quem saiu.
        $identity = AuthContext::current()->identity;

        Session::invalidate();
        Session::regenerateCsrfToken();
        AuthContext::set(null, $this->guardName);
        Events::dispatch(new UserLoggedOut($identity, $this->guardName));
    }
}
