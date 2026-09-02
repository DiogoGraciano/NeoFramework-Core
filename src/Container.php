<?php
declare(strict_types=1);

namespace NeoFramework\Core;

use DI\Container as DIContainer;
use DI\ContainerBuilder;
use GuzzleHttp\Psr7\HttpFactory;
use NeoFramework\Core\Auth\Authorizer;
use NeoFramework\Core\Auth\BearerTokenGuard;
use NeoFramework\Core\Auth\GuardRegistry;
use NeoFramework\Core\Auth\InMemoryTokenRepository;
use NeoFramework\Core\Auth\LoginThrottle;
use NeoFramework\Core\Auth\NullUserProvider;
use NeoFramework\Core\Auth\PolicyRegistry;
use NeoFramework\Core\Auth\SessionGuard;
use NeoFramework\Core\Auth\TokenRepositoryInterface;
use NeoFramework\Core\Auth\UserProviderInterface;
use NeoFramework\Core\Config\AppConfig;
use NeoFramework\Core\Config\AuthConfig;
use NeoFramework\Core\Config\CacheConfig;
use NeoFramework\Core\Config\ConfigRepository;
use NeoFramework\Core\Config\ConfigRepositoryInterface;
use NeoFramework\Core\Config\ConfigurationException;
use NeoFramework\Core\Config\ConfigValidator;
use NeoFramework\Core\Config\CorsConfig;
use NeoFramework\Core\Config\CryptoConfig;
use NeoFramework\Core\Config\DatabaseConfig;
use NeoFramework\Core\Config\HttpConfig;
use NeoFramework\Core\Config\LoggingConfig;
use NeoFramework\Core\Config\MailConfig;
use NeoFramework\Core\Config\QueueConfig;
use NeoFramework\Core\Config\RateLimitConfig;
use NeoFramework\Core\Config\RuntimeConfig;
use NeoFramework\Core\Config\SecurityHeadersConfig;
use NeoFramework\Core\Config\SessionConfig;
use NeoFramework\Core\Config\StorageConfig;
use NeoFramework\Core\Config\TemplateConfig;
use NeoFramework\Core\Config\ViteConfig;
use NeoFramework\Core\Mail\MailerInterface;
use NeoFramework\Core\Observability\MetricsExporterInterface;
use NeoFramework\Core\Observability\NullMetricsExporter;
use NeoFramework\Core\Observability\NullTracer;
use NeoFramework\Core\Observability\TracerInterface;
use NeoFramework\Core\RateLimit\RateLimiterFactory;
use NeoFramework\Core\RateLimit\RateLimiterInterface;
use NeoFramework\Core\RateLimit\RateLimitPolicyRegistry;
use NeoFramework\Core\RateLimit\RateLimitStoreInterface;
use NeoFramework\Core\Validation\RespectValidator;
use NeoFramework\Core\Validation\ValidatorInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Log\LoggerInterface;
use function DI\factory;
use function DI\get;

class Container implements \Psr\Container\ContainerInterface
{
    /**
     * O container é caro de construir (varre atributos e monta o autowiring).
     * Reconstruí-lo a cada get() jogava fora todo o trabalho e quebrava
     * qualquer definição com escopo de singleton.
     */
    private static ?DIContainer $instance = null;

    public function load():DIContainer
    {
        if (self::$instance === null) {
            $builder = new ContainerBuilder();
            $builder->useAttributes(true);
            $builder->useAutowiring(true);
            $builder->addDefinitions([
                RequestFactoryInterface::class => get(HttpFactory::class),
                ResponseFactoryInterface::class => get(HttpFactory::class),
                ServerRequestFactoryInterface::class => get(HttpFactory::class),
                StreamFactoryInterface::class => get(HttpFactory::class),
                UploadedFileFactoryInterface::class => get(HttpFactory::class),
                UriFactoryInterface::class => get(HttpFactory::class),
                LoggerInterface::class => factory([Logger::class, 'instance']),
                ConfigRepositoryInterface::class => factory(static fn (): ConfigRepositoryInterface => ConfigRepository::fromRoot()),
                AppConfig::class => factory(static fn (ConfigRepositoryInterface $config): AppConfig => AppConfig::from($config)),
                HttpConfig::class => factory(static fn (ConfigRepositoryInterface $config): HttpConfig => HttpConfig::from($config)),
                CorsConfig::class => factory(static fn (ConfigRepositoryInterface $config): CorsConfig => CorsConfig::from($config)),
                SecurityHeadersConfig::class => factory(static fn (ConfigRepositoryInterface $config): SecurityHeadersConfig => SecurityHeadersConfig::from($config)),
                SessionConfig::class => factory(static fn (ConfigRepositoryInterface $config): SessionConfig => SessionConfig::from($config)),
                CacheConfig::class => factory(static fn (ConfigRepositoryInterface $config): CacheConfig => CacheConfig::from($config)),
                LoggingConfig::class => factory(static fn (ConfigRepositoryInterface $config): LoggingConfig => LoggingConfig::from($config)),
                QueueConfig::class => factory(static fn (ConfigRepositoryInterface $config): QueueConfig => QueueConfig::from($config)),
                DatabaseConfig::class => factory(static fn (ConfigRepositoryInterface $config): DatabaseConfig => DatabaseConfig::from($config)),
                TemplateConfig::class => factory(static fn (ConfigRepositoryInterface $config): TemplateConfig => TemplateConfig::from($config)),
                ViteConfig::class => factory(static fn (ConfigRepositoryInterface $config): ViteConfig => ViteConfig::from($config)),
                MailConfig::class => factory(static fn (ConfigRepositoryInterface $config): MailConfig => MailConfig::from($config)),
                CryptoConfig::class => factory(static fn (ConfigRepositoryInterface $config): CryptoConfig => CryptoConfig::from($config)),
                StorageConfig::class => factory(static fn (ConfigRepositoryInterface $config): StorageConfig => StorageConfig::from($config)),
                RuntimeConfig::class => factory(static fn (ConfigRepositoryInterface $config): RuntimeConfig => RuntimeConfig::from($config)),
                UserProviderInterface::class => get(NullUserProvider::class),
                TokenRepositoryInterface::class => get(InMemoryTokenRepository::class),
                GuardRegistry::class => factory(static fn (SessionGuard $session, BearerTokenGuard $bearer): GuardRegistry => new GuardRegistry($session, $bearer)),
                PolicyRegistry::class => factory(static fn (): PolicyRegistry => new PolicyRegistry()),
                Authorizer::class => factory(static fn (PolicyRegistry $policies): Authorizer => new Authorizer($policies)),
                ValidatorInterface::class => get(RespectValidator::class),
                // No-op por padrão: observabilidade desligada não altera comportamento
                // nem custa mais que uma chamada vazia. A aplicação troca em Config/container.php.
                MetricsExporterInterface::class => get(NullMetricsExporter::class),
                TracerInterface::class => get(NullTracer::class),
                RateLimitConfig::class => factory(static fn (ConfigRepositoryInterface $config): RateLimitConfig => RateLimitConfig::from($config)),
                RateLimiterInterface::class => factory(static fn (RateLimitConfig $config, LoggerInterface $logger): RateLimiterInterface => RateLimiterFactory::fromConfig($config, $logger)),
                RateLimitPolicyRegistry::class => factory(static fn (RateLimitConfig $config): RateLimitPolicyRegistry => RateLimitPolicyRegistry::fromConfig($config)),
                MailerInterface::class => get(\NeoFramework\Core\Email::class),
                RateLimitStoreInterface::class => factory(static fn (RateLimitConfig $config): RateLimitStoreInterface => RateLimiterFactory::storeFromConfig($config)),
                AuthConfig::class => factory(static fn (ConfigRepositoryInterface $config): AuthConfig => AuthConfig::from($config)),
                LoginThrottle::class => factory(static fn (RateLimitStoreInterface $store, AuthConfig $config): LoginThrottle => new LoginThrottle($store, $config->loginThrottleLimit, $config->loginThrottleWindow)),
            ]);

            // A biblioteca não conhece models, providers nem a persistência de
            // tokens da aplicação. Este arquivo permite que o projeto substitua
            // as definições padrão sem acoplar o Core ao seu bootstrap.
            $definitions = \NeoFramework\Core\Support\ProjectRoot::path() . 'Config' . DIRECTORY_SEPARATOR . 'container.php';
            if (is_file($definitions)) {
                $applicationDefinitions = include $definitions;
                if (!is_array($applicationDefinitions)) {
                    throw new ConfigurationException("Container definitions '{$definitions}' must return an array.");
                }
                $builder->addDefinitions($applicationDefinitions);
            }

            if ($this->loadConfig()->isProduction()) {
                $cacheDir = \NeoFramework\Core\Support\ProjectRoot::path() . "Cache" . DIRECTORY_SEPARATOR . "container";

                if (!is_dir($cacheDir)) {
                    @mkdir($cacheDir, 0775, true);
                }

                if (is_dir($cacheDir) && is_writable($cacheDir)) {
                    $builder->enableCompilation($cacheDir);
                    $builder->writeProxiesToFile(true, $cacheDir . DIRECTORY_SEPARATOR . "proxies");
                }
            }

            self::$instance = $builder->build();
        }

        return self::$instance;
    }

    public function get(string $id): mixed {
        return $this->load()->get($id);
    }

    public function has(string $id): bool
    {
        return $this->load()->has($id);
    }

    /**
     * Descarta o container. Útil em testes.
     */
    public static function reset(): void
    {
        self::$instance = null;
        Config::reset();
    }

    private function loadConfig(): AppConfig
    {
        $config = ConfigRepository::fromRoot();
        ConfigValidator::validate($config->all());
        return AppConfig::from($config);
    }
}
