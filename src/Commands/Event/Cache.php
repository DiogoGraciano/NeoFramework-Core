<?php

declare(strict_types=1);

namespace NeoFramework\Core\Commands\Event;

use Ahc\Cli\Input\Command;
use Ahc\Cli\Output\Color;
use NeoFramework\Core\Commands\ExitCode;
use NeoFramework\Core\Events\ListenerCache;
use Throwable;

class Cache extends Command
{
    public function __construct()
    {
        parent::__construct('event:cache', 'Compila o mapa de listeners consumido em produção');

        $this->version('1.0');
    }

    public function execute(): int
    {
        $color = new Color();

        try {
            // `build()` valida cada entrada. Um listener inexistente reprova aqui,
            // e não no meio de uma requisição que já custou caro.
            $map = ListenerCache::build();

            if (!ListenerCache::store($map)) {
                echo $color->error('Não foi possível escrever ' . ListenerCache::file() . PHP_EOL);

                return ExitCode::FAILURE;
            }

            $listeners = array_sum(array_map(count(...), $map));
            echo $color->ok('Mapa de listeners escrito em ' . ListenerCache::file() . PHP_EOL);
            echo $color->info("{$listeners} listeners compilados para " . count($map) . ' eventos' . PHP_EOL);

            return ExitCode::OK;
        } catch (Throwable $e) {
            fwrite(STDERR, $color->error($e->getMessage() . PHP_EOL));

            return ExitCode::FAILURE;
        }
    }
}
