<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/** Explicit Bash-child readiness and release, outside the checkout and without polling. */
final class TaskCheckChild
{
    /** @var resource */
    private $acknowledgements;

    /** @var resource */
    private $release;

    private ?int $pid = null;

    public function __construct(private readonly string $directory)
    {
        foreach (['acknowledgements', 'release'] as $name) {
            if (! posix_mkfifo($directory.'/'.$name, 0600)) {
                throw new RuntimeException('Could not create the check child handshake.');
            }
        }
        // O_RDWR keeps FIFO opens non-blocking even before systemd has admitted the child.
        $this->acknowledgements = fopen($directory.'/acknowledgements', 'r+');
        $this->release = fopen($directory.'/release', 'r+');
        stream_set_blocking($this->acknowledgements, false);
    }

    public function readyCommand(): string
    {
        return 'printf "%s\\n" "$BASHPID" > '.escapeshellarg($this->directory.'/acknowledgements');
    }

    public function waitCommand(): string
    {
        return 'read -r release < '.escapeshellarg($this->directory.'/release');
    }

    public function awaitReady(): void
    {
        $read = [$this->acknowledgements];
        $write = $except = [];
        if (stream_select($read, $write, $except, 10) !== 1) {
            throw new RuntimeException('The managed check child did not acknowledge readiness.');
        }
        $line = fgets($this->acknowledgements);
        if (! is_string($line) || ! preg_match('/^[1-9][0-9]*\n$/D', $line)) {
            throw new RuntimeException('The managed check child sent an invalid acknowledgement.');
        }
        $this->pid = (int) $line;
    }

    public function release(): void
    {
        fwrite($this->release, "continue\n");
        fflush($this->release);
    }

    public function waitForExit(): void
    {
        if ($this->pid === null) {
            throw new RuntimeException('Wait for the child acknowledgement before its exit.');
        }
        \check_runner_wait_for_pid($this->pid);
    }

    public function __destruct()
    {
        fclose($this->acknowledgements);
        fclose($this->release);
    }
}
