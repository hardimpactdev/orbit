<?php

declare(strict_types=1);

namespace App\Services;

class StreamJsonIdleStepWriter
{
    /** @var resource|null */
    private mixed $process = null;

    /**
     * @param  callable(string): void  $write
     * @param  resource|null  $stdout
     */
    public function start(string $line, callable $write, int $intervalSeconds = 1, mixed $stdout = null): void
    {
        $this->stop();

        $stdout = $this->resolveStdout($stdout);

        if ($stdout === null || ! $this->canSpawn()) {
            return;
        }

        /** @var array{0: array{0: 'file', 1: non-empty-string, 2: 'r'}, 1: resource, 2: resource|array{0: 'file', 1: non-empty-string, 2: 'w'}} $descriptorSpec */
        $descriptorSpec = [
            ['file', '/dev/null', 'r'],
            $stdout,
            $this->resolveStderr(),
        ];
        $pipes = null;

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
            $descriptorSpec,
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
        return function_exists('proc_open') && is_executable('/bin/sh');
    }

    /**
     * @return resource|null
     */
    private function resolveStdout(mixed $stdout): mixed
    {
        if (is_resource($stdout)) {
            return $stdout;
        }

        if (defined('STDOUT') && is_resource(STDOUT)) {
            return STDOUT;
        }

        return null;
    }

    /**
     * @return resource|array{0: string, 1: string, 2: string}
     */
    private function resolveStderr(): mixed
    {
        if (defined('STDERR') && is_resource(STDERR)) {
            return STDERR;
        }

        return ['file', '/dev/stderr', 'w'];
    }
}
