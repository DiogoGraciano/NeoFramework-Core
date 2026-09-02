<?php
declare(strict_types=1);

namespace NeoFramework\Core\Commands;

use Ahc\Cli\Input\Command;
use NeoFramework\Core\Support\StubGenerator;

final class Make extends Command
{
    /** @var array<string,array{directory:string,namespace:string,suffix:string,stub:string}> */
    private const TYPES = [
        'controller' => ['directory' => 'App/Controllers', 'namespace' => 'App\\Controllers', 'suffix' => 'Controller', 'stub' => 'controller'],
        'middleware' => ['directory' => 'App/Middleware', 'namespace' => 'App\\Middleware', 'suffix' => 'Middleware', 'stub' => 'middleware'],
        'request' => ['directory' => 'App/Requests', 'namespace' => 'App\\Requests', 'suffix' => 'Request', 'stub' => 'request'],
        'policy' => ['directory' => 'App/Policies', 'namespace' => 'App\\Policies', 'suffix' => 'Policy', 'stub' => 'policy'],
        'job' => ['directory' => 'App/Jobs', 'namespace' => 'App\\Jobs', 'suffix' => 'Job', 'stub' => 'job'],
        'command' => ['directory' => 'App/Commands', 'namespace' => 'App\\Commands', 'suffix' => 'Command', 'stub' => 'command'],
        'test' => ['directory' => 'Tests', 'namespace' => 'Tests', 'suffix' => 'Test', 'stub' => 'test'],
    ];

    public function __construct(private readonly string $type)
    {
        if (!isset(self::TYPES[$type])) throw new \InvalidArgumentException("Unsupported make type: {$type}");
        parent::__construct('make:' . $type, "Create a {$type} from a stub");
        $this->argument('<name>', 'Class name; directories use / or \\')
            ->option('-f --force', 'Overwrite an existing generated file')
            ->option('-j --json', 'Emit stable JSON for automation');
    }

    public function execute(string $name, ?bool $force): int
    {
        try {
            $result = (new StubGenerator(\NeoFramework\Core\Support\ProjectRoot::path(), dirname(__DIR__, 2)))->make($name, self::TYPES[$this->type], (bool) $force);
            if ($this->json) echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
            elseif ($result['status'] === 'exists') fwrite(STDERR, "Exists: {$result['path']} (use --force to overwrite)\n");
            else echo "Created: {$result['path']}\n";

            return $result['status'] === 'created' ? ExitCode::OK : ExitCode::FAILURE;
        } catch (\Throwable $error) {
            fwrite(STDERR, $error->getMessage() . PHP_EOL);
            return ExitCode::FAILURE;
        }
    }
}
