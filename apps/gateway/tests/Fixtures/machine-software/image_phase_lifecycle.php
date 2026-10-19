<?php

declare(strict_types=1);

/**
 * Disposable Linux guest acceptance, never a production Node.
 * php tests/Fixtures/machine-software/image_phase_lifecycle.php survive|timeout start|poll <address> <key> <known-hosts> <run-key>
 * Start deliberately SIGKILLs this caller after systemd accepts the phase. Poll runs in a fresh PHP process.
 */
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\TaskVms\RecipeImagePrograms;
use App\Infrastructure\TaskVms\TaskVmGuestShell;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $scenario, $action, $address, $key, $knownHosts] = $argv;
$runKey = $argv[6] ?? 'lifecycle';
if (! in_array($scenario, ['survive', 'timeout'], true) || ! in_array($action, ['start', 'poll'], true)) {
    throw new InvalidArgumentException('Use survive|timeout and start|poll, with a disposable guest address and SSH identity.');
}
if (preg_match('/\A[a-z0-9-]{1,32}\z/D', $runKey) !== 1) {
    throw new InvalidArgumentException('The acceptance run key must be a short lowercase identifier.');
}
$shell = new class($scenario, $action, $address, $key, $knownHosts, $runKey) implements TaskVmGuestShell
{
    public function __construct(private string $scenario, private string $action, private string $address, private string $key, private string $knownHosts, private string $runKey) {}

    public function asUser(array $command, string|ProtectedInput|null $input = null, float $timeout = 900.0, ?string $cwd = null): CommandResult
    {
        throw new LogicException('This acceptance only exercises the root packages phase.');
    }

    public function asRoot(array $command, string|ProtectedInput|null $input = null, float $timeout = 900.0): CommandResult
    {
        if (is_string($input) && str_contains($input, 'sys.exit(image_main())')) {
            // Keep the actual entry point, platform check, logger and phase protocol; replace only package mutation.
            $seconds = $this->scenario === 'survive' ? 4 : 60;
            $probe = <<<'PYTHON'
def image_packages(payload):
    import time
    marker = Path('/run/orbit-image-lifecycle-SCENARIO')
    with marker.open('a') as counter:
        counter.write('start\n')
    child = subprocess.Popen(['bash', '-c', 'trap "" TERM; echo $$ > /run/orbit-image-child-SCENARIO; exec sleep SECONDS'], start_new_session=True)
    time.sleep(1)
PYTHON;
            $probe = str_replace(['SCENARIO', 'SECONDS'], [$this->scenario.'-'.$this->runKey, (string) $seconds], $probe);
            $input = str_replace('    sys.exit(image_main())', '    exec('.json_encode($probe, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).")\n    sys.exit(image_main())", $input);
        }
        if ($command[0] === 'systemd-run' && $this->scenario === 'timeout') {
            $command = array_map(static fn (string $argument): string => match ($argument) {
                '--property=RuntimeMaxSec='.RecipeImagePrograms::PhaseSeconds => '--property=RuntimeMaxSec=2',
                '--property=TimeoutStopSec=30' => '--property=TimeoutStopSec=1',
                default => $argument,
            }, $command);
        }
        $runner = new NativeProcessRunner;
        $result = $runner->run(new ProcessInvocation(
            ['ssh', '-F', '/dev/null', '-i', $this->key, '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes',
                '-o', 'UserKnownHostsFile='.$this->knownHosts, '--', 'root@'.$this->address,
                implode(' ', array_map(escapeshellarg(...), $command))],
            timeout: $timeout, input: is_string($input) ? $input : null,
            protectedInput: $input instanceof ProtectedInput ? $input : null,
        ));
        if (! $result->succeeded()) {
            fwrite(STDERR, json_encode($command, JSON_THROW_ON_ERROR)."\n".$result->stderr);
        }
        if ($command[0] === 'systemd-run' && $result->succeeded() && $this->action === 'start') {
            fwrite(STDOUT, "Guest accepted the phase; killing this queue-worker stand-in before its acknowledgement.\n");
            posix_kill(getmypid(), SIGKILL);
        }

        return $result;
    }
};
try {
    $completed = app(RecipeImagePrograms::class)->poll($shell, new RecipeLoader()->load(), 'base', 'packages', $runKey.'-'.$scenario);
    echo json_encode(['completed' => $completed], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
