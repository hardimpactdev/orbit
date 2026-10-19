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

    public function __construct(private readonly int $timeoutSeconds = 60)
    {
        $process = proc_open(['python3', '-I', __DIR__.'/../Fixtures/remote-program-host.py'], [['pipe', 'r'], ['pipe', 'w'], STDERR], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the Python program host.');
        }
        $this->process = $process;
        $this->pipes = $pipes;
        stream_set_timeout($this->pipes[1], $timeoutSeconds + 5);
    }

    /** @param array<string, string> $environment */
    public function run(string $bootstrap, string $name, string $input, array $environment = []): CommandResult
    {
        $request = json_encode(['bootstrap' => $bootstrap, 'name' => $name, 'input' => base64_encode($input), 'cwd' => getcwd(), 'environment' => $environment, 'timeout' => $this->timeoutSeconds], JSON_THROW_ON_ERROR)."\n";
        while ($request !== '') {
            $written = fwrite($this->pipes[0], $request);
            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not send a program to the Python host.');
            }
            $request = substr($request, $written);
        }
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
