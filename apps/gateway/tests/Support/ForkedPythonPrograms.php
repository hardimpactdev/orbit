<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Processes\CommandResult;
use RuntimeException;

/** Shares immutable Python setup, not program state, filesystem changes, or process groups. */
final class ForkedPythonPrograms
{
    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes;

    public function __construct(private readonly float $timeoutSeconds = 60)
    {
        $process = proc_open(['python3', '-I', __DIR__.'/../Fixtures/remote-program-host.py'], [['pipe', 'r'], ['pipe', 'w'], STDERR], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the Python program host.');
        }
        $this->process = $process;
        $this->pipes = $pipes;
    }

    /** @param array<string, string|false> $environment */
    public function run(string $bootstrap, string $name, string $input, array $environment = []): CommandResult
    {
        return $this->execute($bootstrap, $name, explode("\0", $input, 2)[0], $input, ['-c', $name], $environment, $this->timeoutSeconds);
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string|false>  $environment
     */
    public function runCommand(string $source, array $arguments, string $input = '', array $environment = [], ?float $timeoutSeconds = null): CommandResult
    {
        return $this->execute("exec(__program__, {'__name__': '__main__'})", '<remote-command>', $source, $input, ['-c', ...$arguments], $environment, $timeoutSeconds ?? $this->timeoutSeconds);
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string|false>  $environment
     */
    private function execute(string $bootstrap, string $name, string $source, string $input, array $arguments, array $environment, float $timeoutSeconds): CommandResult
    {
        $environment = array_replace(getenv(), $environment);
        $environment['PATH'] = NoSudo::path((string) ($environment['PATH'] ?? ''));
        $encodedEnvironment = [];
        foreach ($environment as $key => $value) {
            $encodedEnvironment[base64_encode($key)] = $value === false ? false : base64_encode($value);
        }
        $request = json_encode(['bootstrap' => $bootstrap, 'name' => $name, 'source' => base64_encode($source), 'input' => base64_encode($input), 'arguments' => $arguments, 'cwd' => getcwd(), 'environment' => $encodedEnvironment, 'timeout' => $timeoutSeconds], JSON_THROW_ON_ERROR)."\n";
        while ($request !== '') {
            $written = fwrite($this->pipes[0], $request);
            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not send a program to the Python host.');
            }
            $request = substr($request, $written);
        }
        stream_set_timeout($this->pipes[1], (int) ceil($timeoutSeconds) + 5);
        $line = fgets($this->pipes[1]);
        if ($line === false) {
            throw new RuntimeException('The Python program host exited without a result.');
        }
        $result = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

        return new CommandResult($result['exit'], base64_decode($result['stdout'], true), base64_decode($result['stderr'], true), 0, false);
    }

    public function close(): void
    {
        if (! is_resource($this->process)) {
            return;
        }
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($this->process);
    }

    public function __destruct()
    {
        $this->close();
    }
}
