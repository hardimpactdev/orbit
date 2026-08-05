<?php

declare(strict_types=1);

/**
 * Guard the single internal operation-payload stdin boundary.
 *
 * OperationTokenGuard drains process STDIN during verify. Internal commands must
 * read operation payloads only through InternalExecutorCommand::stdin() /
 * OperationStdinBuffer — never raw STDIN or private StreamableInput readers.
 */
describe('internal command stdin boundary', function (): void {
    it('keeps OperationStdinBuffer as the only raw stdin capture for InternalExecutorCommand', function (): void {
        $basePath = app_path('Commands/Internal/InternalExecutorCommand.php');
        $base = file_get_contents($basePath);

        expect($base)->toBeString();
        expect($base)
            ->toContain('OperationStdinBuffer')
            ->and($base)
            ->toContain('protected function stdin(): string')
            ->and($base)
            ->toContain('captureOperationStdin')
            ->and($base)
            ->not->toContain('stream_get_contents');
    });

    it('forbids duplicate raw STDIN or private stdin readers under Internal commands', function (): void {
        $directory = app_path('Commands/Internal');
        $violations = [];

        foreach (glob($directory.'/*.php') ?? [] as $path) {
            $basename = basename((string) $path);

            if ($basename === 'InternalExecutorCommand.php') {
                continue;
            }

            $contents = file_get_contents($path);

            if ($contents === false) {
                $violations[] = "{$basename}: unreadable";

                continue;
            }

            $needles = [
                'stream_get_contents',
                'STDIN',
                'StreamableInputInterface',
                'private function stdin(',
                'protected function stdin(',
            ];

            foreach ($needles as $needle) {
                if (! str_contains($contents, $needle)) {
                    continue;
                }

                $violations[] = "{$basename}: contains {$needle}";
            }
        }

        expect($violations)->toBeEmpty();
    });

    it('keeps OperationStdinBuffer as the sole process-STDIN capture helper', function (): void {
        $bufferPath = app_path('Services/Executor/OperationStdinBuffer.php');
        $buffer = file_get_contents($bufferPath);

        expect($buffer)->toBeString();
        expect($buffer)
            ->toContain('stream_get_contents')
            ->and($buffer)
            ->toContain('captureFromProcessStdin')
            ->and($buffer)
            ->toContain('captureFromStream')
            ->and($buffer)
            ->toContain('function take(): string');
    });
});
