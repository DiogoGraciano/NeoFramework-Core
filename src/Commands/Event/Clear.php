<?php

declare(strict_types=1);

namespace NeoFramework\Core\Commands\Event;

use Ahc\Cli\Input\Command;
use Ahc\Cli\Output\Color;
use NeoFramework\Core\Commands\ExitCode;
use NeoFramework\Core\Events\ListenerCache;
use Throwable;

class Clear extends Command
{
    public function __construct()
    {
        parent::__construct('event:clear', 'Remove o mapa de listeners compilado');

        $this->version('1.0');
    }

    public function execute(): int
    {
        $color = new Color();

        try {
            if (ListenerCache::clear()) {
                echo $color->ok('Mapa de listeners removido.' . PHP_EOL);

                return ExitCode::OK;
            }

            echo $color->error('Não foi possível remover ' . ListenerCache::file() . PHP_EOL);

            return ExitCode::FAILURE;
        } catch (Throwable $e) {
            fwrite(STDERR, $color->error($e->getMessage() . PHP_EOL));

            return ExitCode::FAILURE;
        }
    }
}
