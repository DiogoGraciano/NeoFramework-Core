<?php

declare(strict_types=1);

namespace NeoFramework\Core\Commands\Event;

use Ahc\Cli\Input\Command;
use Ahc\Cli\Output\Color;
use NeoFramework\Core\Commands\ExitCode;
use NeoFramework\Core\Events\ListenerCache;
use Throwable;

/**
 * Inspeção dos listeners registrados.
 *
 * Um listener silencioso é indistinguível de um listener ausente; este comando
 * é o que torna a diferença visível.
 */
class ListEvents extends Command
{
    public function __construct()
    {
        parent::__construct('event:list', 'List registered event listeners');

        $this->option('-j --json', 'Output as JSON, for tooling')->version('1.0');
    }

    public function execute()
    {
        $color = new Color();
        $compiled = ListenerCache::load() !== null;

        if (!$compiled && !is_file(ListenerCache::source())) {
            echo $color->warn('Nenhum Config/events.php encontrado.' . PHP_EOL);
            return ExitCode::OK;
        }

        try {
            // Lê pelo mesmo caminho do runtime. Listar o arquivo-fonte enquanto a
            // aplicação serve o mapa compilado mostraria listeners que não rodam
            // e esconderia os que rodam — exatamente quando o cache está velho.
            $registered = ListenerCache::provider()->registered();
        } catch (Throwable $e) {
            fwrite(STDERR, $color->error($e->getMessage() . PHP_EOL));
            return ExitCode::FAILURE;
        }

        if ($this->json) {
            echo json_encode($registered, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            return ExitCode::OK;
        }

        if ($registered === []) {
            echo $color->warn('Nenhum listener registrado.' . PHP_EOL);
            return ExitCode::OK;
        }

        $total = 0;
        foreach ($registered as $event => $listeners) {
            echo $color->info(self::shortName($event) . PHP_EOL);
            foreach ($listeners as $listener) {
                echo '  -> ' . $listener . PHP_EOL;
                $total++;
            }
        }

        echo PHP_EOL . $color->info(count($registered) . ' evento(s), ' . $total . ' listener(s)' . PHP_EOL);
        // De onde veio a lista importa: um cache velho é a diferença entre o que
        // está escrito em Config/events.php e o que a aplicação realmente executa.
        echo $color->comment($compiled ? 'Origem: mapa compilado (event:cache).' . PHP_EOL : 'Origem: Config/events.php.' . PHP_EOL);

        return ExitCode::OK;
    }

    private static function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
