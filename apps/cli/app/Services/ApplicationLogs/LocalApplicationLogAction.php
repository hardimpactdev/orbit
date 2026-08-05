<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

use Symfony\Component\Process\Process;

final readonly class LocalApplicationLogAction
{
    public function __construct(
        private ApplicationLogPathGuard $pathGuard,
        private ApplicationLogStreamPublisher $streams,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function read(array $payload): array
    {
        $input = LocalApplicationLogPayload::from($payload);
        $this->pathGuard->assertWithinAuthorizedRoot($input);

        if (! file_exists($input->absolutePath)) {
            return [
                'data' => [
                    'path' => LocalApplicationLogPayload::LogicalPath,
                    'file_exists' => false,
                    'lines' => [],
                ],
                'meta' => [],
            ];
        }

        if (! is_readable($input->absolutePath)) {
            throw new LocalApplicationLogFailure(
                errorCode: 'application_log.unreadable',
                message: 'The Laravel application log exists but is unreadable.',
                meta: ['path' => LocalApplicationLogPayload::LogicalPath],
            );
        }

        $result = $this->run([
            'tail',
            '-n',
            (string) $input->lines,
            $input->absolutePath,
        ]);

        if (! $result->isSuccessful()) {
            throw new LocalApplicationLogFailure(
                errorCode: 'application_log.read_failed',
                message: 'The Laravel application log could not be read.',
                meta: [
                    'exit_code' => $result->getExitCode(),
                    'stderr' => trim($result->getErrorOutput()),
                ],
            );
        }

        return [
            'data' => [
                'path' => LocalApplicationLogPayload::LogicalPath,
                'file_exists' => true,
                'lines' => $this->splitLines($result->getOutput()),
            ],
            'meta' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(string): void  $onOutput
     */
    public function stream(array $payload, callable $onOutput): int
    {
        $input = LocalApplicationLogPayload::from($payload);
        $this->pathGuard->assertWithinAuthorizedRoot($input);

        return $this->streams->stream($input, $this->followCommand($input), $onOutput);
    }

    /**
     * @return list<string>
     */
    private function followCommand(LocalApplicationLogPayload $input): array
    {
        return [
            'tail',
            '-F',
            '-n',
            (string) $input->lines,
            $input->absolutePath,
        ];
    }

    /**
     * @return list<string>
     */
    private function splitLines(string $output): array
    {
        if ($output === '') {
            return [];
        }

        $lines = preg_split('/\R/', rtrim($output, "\r\n")) ?: [];

        return array_values($lines);
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command): Process
    {
        $process = new Process($command);
        $process->setTimeout(120);
        $process->run();

        return $process;
    }
}
