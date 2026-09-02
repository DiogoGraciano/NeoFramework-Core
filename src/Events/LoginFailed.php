<?php

declare(strict_types=1);

namespace NeoFramework\Core\Events;

/**
 * Uma verificação de credencial falhou.
 *
 * Carrega o identificador tentado e o IP de origem — nunca a senha. O
 * identificador é o dado que torna o evento útil para auditoria: sem ele não há
 * como distinguir um usuário que errou a própria senha de um ataque dirigido a
 * uma conta específica.
 */
final readonly class LoginFailed
{
    public function __construct(public string $identifier, public string $ip)
    {
    }
}
