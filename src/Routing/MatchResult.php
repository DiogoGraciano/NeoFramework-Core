<?php
declare(strict_types=1);

namespace NeoFramework\Core\Routing;

final readonly class MatchResult
{
    private function __construct(public string $status, public ?RouteDefinition $route = null, public array $variables = [], public array $allowedMethods = []) {}
    public static function found(RouteDefinition $route, array $variables = []): self { return new self('found', $route, $variables); }
    public static function notFound(): self { return new self('not_found'); }
    public static function methodNotAllowed(array $allowed): self { sort($allowed); return new self('method_not_allowed', null, [], array_values(array_unique($allowed))); }
    /** OPTIONS respondido pelo próprio matcher, sem executar controller. */
    public static function options(array $allowed): self { sort($allowed); return new self('options', null, [], array_values(array_unique($allowed))); }
}
