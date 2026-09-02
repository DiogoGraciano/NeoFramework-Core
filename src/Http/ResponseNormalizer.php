<?php
declare(strict_types=1);

namespace NeoFramework\Core\Http;

use JsonSerializable;
use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Abstract\Layout;
use NeoFramework\Core\Response;
use Psr\Http\Message\ResponseInterface;

final class ResponseNormalizer
{
    public static function normalize(mixed $value, Controller $controller): ResponseInterface
    {
        if ($value instanceof ResponseInterface) return $value;
        if ($value === null) return $controller->getResponse();
        if ($value instanceof Layout || is_string($value)) return (new Response())->html($value);
        if (is_array($value) || $value instanceof JsonSerializable) return (new Response())->json($value);
        throw new \LogicException('A action deve retornar ResponseInterface, Layout, string, array, JsonSerializable ou null.');
    }
}
