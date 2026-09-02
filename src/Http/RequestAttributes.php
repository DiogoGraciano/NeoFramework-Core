<?php
declare(strict_types=1);
namespace NeoFramework\Core\Http;

final class RequestAttributes
{
    public const ROUTE = 'neoframework.route';
    public const ROUTE_VARIABLES = 'neoframework.route_variables';
    public const BASE_PATH = 'neoframework.base_path';
    public const SCOPE = 'neoframework.scope';
    /** Identidade semeada exclusivamente por TestClient. */
    public const AUTH_IDENTITY = 'neoframework.auth_identity';
    public const AUTH_GUARD = 'neoframework.auth_guard';
}
