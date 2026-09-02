<?php
declare(strict_types=1);

namespace NeoFramework\Core\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class Authenticated
{
    /** @param list<string> $scopes */
    public function __construct(public string $guard = 'session', public array $scopes = []) {}
}
