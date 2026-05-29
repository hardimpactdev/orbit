<?php

declare(strict_types=1);

namespace App\E2E\Commands;

use App\E2E\Support\E2EDevTopologyManifestStore;
use App\E2E\Support\E2EResourceLease;
use RuntimeException;

final readonly class E2EDevTopologyReleaseCommand
{
    public function __construct(
        private string $repoRoot,
    ) {}

    /**
     * @param  list<string>  $argv
     */
    public function run(array $argv): int
    {
        $options = $this->parse($argv);

        if ($options['help']) {
            $this->renderHelp();

            return 0;
        }

        if ($options['id'] === '') {
            return $this->renderError('validation_failed', 'A retained E2E topology id is required.', $options['json']);
        }

        $store = E2EDevTopologyManifestStore::fromEnvironment($this->repoRoot);
        $manifest = $store->read($options['id']);

        if ($manifest === null) {
            return $this->renderError('not_found', "Retained E2E topology [{$options['id']}] was not found.", $options['json']);
        }

        try {
            $cleanupCommands = $this->cleanupCommands($manifest);
            $leases = $this->leases($manifest);

            foreach ($cleanupCommands as $command) {
                $this->runCleanupCommand($command);
            }

            foreach ($leases as $lease) {
                E2EResourceLease::fromMetadata($lease)->release();
            }

            $store->remove($options['id']);
        } catch (RuntimeException $exception) {
            return $this->renderError('release_failed', $exception->getMessage(), $options['json']);
        }

        $payload = [
            'id' => $options['id'],
            'cleanup_commands' => count($cleanupCommands),
            'leases' => count($leases),
        ];

        if ($options['json']) {
            $this->writeJson(['success' => ['released' => $payload]]);

            return 0;
        }

        $this->line("Released retained E2E topology [{$options['id']}].");
        $this->line("Cleanup commands: {$payload['cleanup_commands']}");
        $this->line("Leases released: {$payload['leases']}");

        return 0;
    }

    /**
     * @param  list<string>  $argv
     * @return array{id: string, json: bool, help: bool}
     */
    private function parse(array $argv): array
    {
        $options = [
            'id' => '',
            'json' => false,
            'help' => false,
        ];

        foreach (array_slice($argv, 1) as $argument) {
            if ($argument === '--json') {
                $options['json'] = true;

                continue;
            }

            if ($argument === '--help' || $argument === '-h') {
                $options['help'] = true;

                continue;
            }

            if (str_starts_with($argument, '-')) {
                continue;
            }

            if ($options['id'] === '') {
                $options['id'] = $argument;
            }
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private function cleanupCommands(array $manifest): array
    {
        $commands = $manifest['cleanup_commands'] ?? [];

        if (! is_array($commands)) {
            throw new RuntimeException('Retained E2E topology cleanup commands are malformed.');
        }

        return array_values(array_filter($commands, is_string(...)));
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<array<string, mixed>>
     */
    private function leases(array $manifest): array
    {
        $leases = $manifest['leases'] ?? [];

        if (! is_array($leases)) {
            throw new RuntimeException('Retained E2E topology leases are malformed.');
        }

        return array_values(array_filter($leases, is_array(...)));
    }

    private function runCleanupCommand(string $command, int $timeoutSeconds = 60): void
    {
        $process = proc_open(
            $command,
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->repoRoot,
        );

        if (! is_resource($process)) {
            throw new RuntimeException("Failed to start retained topology cleanup command [{$command}].");
        }

        $deadline = microtime(true) + $timeoutSeconds;
        $exitCode = null;

        do {
            $status = proc_get_status($process);

            if (! $status['running']) {
                $exitCode = $status['exitcode'];

                break;
            }

            if (microtime(true) >= $deadline) {
                proc_terminate($process);

                throw new RuntimeException("Retained topology cleanup command timed out [{$command}].");
            }

            usleep(100_000);
        } while (true);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        proc_close($process);

        if ($exitCode !== 0) {
            $output = trim(($stdout === false ? '' : $stdout).PHP_EOL.($stderr === false ? '' : $stderr));

            throw new RuntimeException("Retained topology cleanup command failed [{$command}]: {$output}");
        }
    }

    private function renderHelp(): void
    {
        $this->line('Usage: e2e:dev-topology:release <id> [--json]');
        $this->line('Releases retained apps/e2e topology manifests and retained resource leases.');
        $this->line('Retained topology debugging is not a replacement for Pest E2E assertions.');
    }

    private function renderError(string $code, string $message, bool $json): int
    {
        if ($json) {
            $this->writeJson([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                ],
            ]);

            return 1;
        }

        fwrite(STDERR, $message.PHP_EOL);

        return 1;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(array $payload): void
    {
        $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function line(string $line): void
    {
        fwrite(STDOUT, $line.PHP_EOL);
    }
}
