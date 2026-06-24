<?php

declare(strict_types=1);

namespace Orbit\Core\Progress;

final class StreamedStepProcessTicker
{
    /** @var resource|null */
    private mixed $process = null;

    /**
     * @param  list<string>  $frames
     */
    public function start(
        array $frames,
        int $intervalMicroseconds = ForkedFrameTicker::DEFAULT_INTERVAL_MICROSECONDS,
    ): bool {
        $this->stop();

        if ($frames === [] || ! $this->canSpawn()) {
            return false;
        }

        $pipes = [];

        $process = proc_open(
            [
                '/bin/sh',
                '-c',
                <<<'SH'
                    parent=$1
                    interval=$2
                    frame_one=$3
                    frame_two=$4
                    current=0

                    while kill -0 "$parent" 2>/dev/null; do
                        sleep "$interval" || exit 0

                        if ! kill -0 "$parent" 2>/dev/null; then
                            exit 0
                        fi

                        if [ "$current" = 0 ]; then
                            printf '%s' "$frame_one" || exit 0
                            current=1
                        else
                            printf '%s' "$frame_two" || exit 0
                            current=0
                        fi
                    done
                    SH,
                'orbit-streamed-step-ticker',
                (string) getmypid(),
                $this->sleepInterval($intervalMicroseconds),
                $frames[0],
                $frames[1] ?? $frames[0],
            ],
            [
                ['file', '/dev/null',   'r'],
                ['file', '/dev/stdout', 'w'],
                ['file', '/dev/stderr', 'w'],
            ],
            $pipes,
        );

        if (! is_resource($process)) {
            return false;
        }

        $this->process = $process;

        return true;
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
        return (
            function_exists('proc_open')
            && is_executable('/bin/sh')
            && file_exists('/dev/stdout')
            && file_exists('/dev/stderr')
        );
    }

    private function sleepInterval(int $intervalMicroseconds): string
    {
        $seconds = max(1, $intervalMicroseconds) / 1_000_000;
        $formatted = rtrim(rtrim(sprintf('%.6F', $seconds), '0'), '.');

        return $formatted === '' ? '0.001' : $formatted;
    }
}
