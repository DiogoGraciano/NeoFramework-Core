<?php
declare(strict_types=1);

namespace NeoFramework\Core\Config;

final readonly class AuthConfig
{
    private function __construct(public int $loginThrottleLimit, public int $loginThrottleWindow) {}

    public static function from(ConfigRepositoryInterface $c): self
    {
        $limit = ConfigValidator::int($c, 'auth.login_throttle.limit', 5);
        $window = ConfigValidator::int($c, 'auth.login_throttle.window', 900);

        if ($limit < 1) throw new ConfigurationException("Configuration key 'auth.login_throttle.limit' must be a positive integer.");
        if ($window < 1) throw new ConfigurationException("Configuration key 'auth.login_throttle.window' must be a positive integer.");

        return new self($limit, $window);
    }
}
