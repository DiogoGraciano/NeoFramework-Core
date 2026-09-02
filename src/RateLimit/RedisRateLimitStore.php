<?php
declare(strict_types=1);

namespace NeoFramework\Core\RateLimit;

use NeoFramework\Core\Config\RateLimitConfig;
use Redis as PhpRedis;
use RedisException;
use RuntimeException;

/**
 * Store atômico para tráfego concorrente em múltiplos processos.
 *
 * O incremento e a expiração acontecem dentro de um único script Lua: o Redis
 * executa scripts de forma serializada, então dois workers nunca leem o mesmo
 * contador antes de gravá-lo — a falha que o `CacheRateLimitStore` tem por
 * construção.
 */
final class RedisRateLimitStore implements RateLimitStoreInterface
{
    /**
     * `EXPIREAT` só é aplicado na primeira requisição da janela. Renová-lo a
     * cada hit empurraria o vencimento para frente e a janela nunca fecharia.
     */
    private const INCREMENT_SCRIPT = <<<'LUA'
    local count = redis.call('INCR', KEYS[1])
    if count == 1 then
        redis.call('EXPIREAT', KEYS[1], ARGV[1])
    end
    return count
    LUA;

    public function __construct(private readonly PhpRedis $redis, private readonly string $prefix = 'neoframework:rl:') {}

    public static function fromConfig(RateLimitConfig $config): self
    {
        if (!extension_loaded('redis')) throw new RuntimeException('The redis extension is required by the redis rate limit store.');

        $redis = new PhpRedis();

        try {
            if (!$redis->connect($config->redisHost, $config->redisPort, $config->redisTimeout)) {
                throw new RuntimeException("Failed to connect to Redis at {$config->redisHost}:{$config->redisPort}.");
            }
            if ($config->redisPassword !== '') $redis->auth($config->redisPassword);
        } catch (RedisException $e) {
            throw new RuntimeException('Failed to connect to the rate limit Redis: ' . $e->getMessage(), 0, $e);
        }

        return new self($redis, $config->redisPrefix);
    }

    public function increment(string $key, int $expiresAt): int
    {
        /** @var mixed $count */
        $count = $this->redis->eval(self::INCREMENT_SCRIPT, [$this->prefix . $key, (string) $expiresAt], 1);
        $error = $this->redis->getLastError();
        if ($error !== null) {
            $this->redis->clearLastError();
            throw new RuntimeException('The rate limit script failed: ' . $error);
        }
        if (!is_int($count)) throw new RuntimeException('The rate limit script returned a non-numeric counter.');

        return $count;
    }

    public function read(string $key): int
    {
        /** @var mixed $count */
        $count = $this->redis->get($this->prefix . $key);

        return is_string($count) || is_int($count) ? (int) $count : 0;
    }

    public function forget(string $key): void
    {
        $this->redis->del($this->prefix . $key);
    }
}
