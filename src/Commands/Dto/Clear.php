<?php

declare(strict_types=1);

namespace NeoFramework\Core\Commands\Dto;

use Ahc\Cli\Input\Command;
use Ahc\Cli\Output\Color;
use NeoFramework\Core\Commands\ExitCode;
use NeoFramework\Core\Http\DtoCache;
use Throwable;

class Clear extends Command
{
    public function __construct()
    {
        parent::__construct('dto:clear', 'Remove a metadata de DTO compilada');

        $this->version('1.0');
    }

    public function execute(): int
    {
        $color = new Color();

        try {
            if (DtoCache::clear()) {
                echo $color->ok('Metadata de DTO removida.' . PHP_EOL);

                return ExitCode::OK;
            }

            echo $color->error('Não foi possível remover ' . DtoCache::file() . PHP_EOL);

            return ExitCode::FAILURE;
        } catch (Throwable $e) {
            fwrite(STDERR, $color->error($e->getMessage() . PHP_EOL));

            return ExitCode::FAILURE;
        }
    }
}
