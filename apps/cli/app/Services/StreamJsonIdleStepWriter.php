<?php

declare(strict_types=1);

namespace App\Services;

class StreamJsonIdleStepWriter
{
    /** @var resource|null */
    private mixed $process = null;

    /**
     * @param  callable(string): void  $write
     */
    public function start(string $line, callable $write, int $intervalSeconds = 1): void
    {
        $this->stop();

        if (! $this->canSpawn()) {
            return;
        }

        $process = proc_open(
            [
                '/bin/sh',
                '-c',
                <<<'SH'
line=$1
interval=$2

while :; do
    sleep "$interval"
    printf '%s' "$line"
done
SH,
                'orbit-stream-json-idle',
                $line,
                (string) max(1, $intervalSeconds),
            ],
            [
                ['file', '/dev/null', 'r'],
                ['file', '/dev/stdout', 'w'],
                ['file', '/dev/stderr', 'w'],
            ],
            $pipes,
        );

        if (! is_resource($process)) {
            return;
        }

        $this->process = $process;
    }

    public function stop(): void
    {
        $process = $this->process;
        $this->process = null;

        if (! is_resource($process)) {
            return;
        }

        proc_terminate($process);
        proc_close($process);
    }

    public function __destruct()
    {
        $this->stop();
    }

    private function canSpawn(): bool
    {
        return function_exists('proc_open')
            && is_executable('/bin/sh')
            && file_exists('/dev/stdout')
            && file_exists('/dev/stderr');
    }
}
