<?php

declare(strict_types=1);

namespace NeoFramework\Core\Commands\Dto;

use Ahc\Cli\Input\Command;
use Ahc\Cli\Output\Color;
use NeoFramework\Core\Commands\ExitCode;
use NeoFramework\Core\Http\DtoCache;
use Throwable;

class Cache extends Command
{
    public function __construct()
    {
        parent::__construct('dto:cache', 'Compila a metadata dos DTOs alcançáveis pelas rotas');

        $this->version('1.0');
    }

    public function execute(): int
    {
        $color = new Color();

        try {
            $map = DtoCache::build();

            if (!DtoCache::store($map)) {
                echo $color->error('Não foi possível escrever ' . DtoCache::file() . PHP_EOL);

                return ExitCode::FAILURE;
            }

            echo $color->ok('Metadata de DTO escrita em ' . DtoCache::file() . PHP_EOL);
            echo $color->info(count($map) . ' DTO(s) compilados' . PHP_EOL);

            return ExitCode::OK;
        } catch (Throwable $e) {
            fwrite(STDERR, $color->error($e->getMessage() . PHP_EOL));

            return ExitCode::FAILURE;
        }
    }
}
