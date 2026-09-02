<?php
declare(strict_types=1);
namespace NeoFramework\Core\Events;

use NeoFramework\Core\Routing\RouteDefinition;
use Psr\Http\Message\ServerRequestInterface;
/** @property-read array<string,string> $variables */
final readonly class RouteMatched
{
    /** @param array<string,string> $variables */
    public function __construct(public ServerRequestInterface $request, public RouteDefinition $route, public array $variables) {}
    public function name(): ?string { return $this->route->name; }
    public function action(): string { return $this->route->controller . '::' . $this->route->action; }
}
