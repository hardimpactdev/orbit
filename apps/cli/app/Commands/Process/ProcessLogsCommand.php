<?php

declare(strict_types=1);

namespace App\Commands\Process;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Services\GatewayOperationStreamSubscriber;
use RuntimeException;

final class ProcessLogsCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'process:log
        {name? : Process name}
        {--node= : Owning node name}
        {--instance= : Instance selector}
        {--workspace= : Workspace name}
        {--follow : Follow log output}
        {--lines=100 : Number of historical lines}
        {--json}';

    #[\Override]
    protected $description = 'Read process runtime logs.';

    public function handle(): int
    {
        $name = $this->stringArgument('name');
        $node = $this->stringOption('node');
        $app = $node === null
            ? $this->stringOption('instance') ?? $this->instanceFromOrbitMarker()
            : $this->stringOption('instance');
        $workspace = $this->stringOption('workspace');

        if ($name === null) {
            return $this->renderFailure('validation_failed', 'The process name is required.', ['field' => 'name']);
        }

        if ($node !== null && ($app !== null || $workspace !== null)) {
            return $this->renderFailure(
                'validation_failed',
                'A node context cannot be combined with instance or workspace context.',
                [
                    'field' => 'context',
                    'node' => $node,
                    'instance' => $app,
                    'workspace' => $workspace,
                ],
            );
        }

        $lines = $this->lines();

        if ($lines === null) {
            return $this->renderFailure('validation_failed', 'The --lines value must be a positive integer.', [
                'field' => 'lines',
            ]);
        }

        if ($this->option('follow') === true && $this->wantsJson()) {
            return $this->renderFailure(
                'validation_failed',
                '--json cannot be combined with --follow for log streams.',
                ['field' => 'json'],
            );
        }

        if ($this->option('follow') === true) {
            return $this->followLogs($name, $lines);
        }

        try {
            $response = $this->gatewayGet('/api/processes/'.rawurlencode($name).'/log', $this->filledQuery([
                'node' => $node,
                'instance' => $app,
                'workspace' => $workspace,
                'lines' => $lines,
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $this->renderLogLines($response);

        return self::SUCCESS;
    }

    private function followLogs(string $name, int $lines): int
    {
        $node = $this->stringOption('node');
        $app = $node === null
            ? $this->stringOption('instance') ?? $this->instanceFromOrbitMarker()
            : $this->stringOption('instance');
        $workspace = $this->stringOption('workspace');

        try {
            $query = $this->filledQuery([
                'node' => $node,
                'instance' => $app,
                'workspace' => $workspace,
                'lines' => $lines,
            ]);
            $write = function (string $chunk): void {
                $this->output->write($chunk);
            };

            $response = $this->gatewayPost('/api/processes/'.rawurlencode($name).'/log-stream', $query);
            $operationRunId = data_get(target: $response, key: 'success.data.operation.uuid');

            if (! is_string($operationRunId) || trim($operationRunId) === '') {
                throw GatewayApiException::streamMalformed(
                    new RuntimeException('Gateway process log stream start response omitted operation uuid.'),
                );
            }

            app(GatewayOperationStreamSubscriber::class)->subscribe(
                trim($operationRunId),
                null,
                function (array $frame) use ($write): void {
                    $this->writeOperationFrame($frame, $write);
                },
            );

            return self::SUCCESS;
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $frame
     * @param  callable(string): void  $write
     */
    private function writeOperationFrame(array $frame, callable $write): void
    {
        if (! in_array($frame['type'] ?? null, ['stdout', 'stderr'], strict: true)) {
            return;
        }

        $data = data_get(target: $frame, key: 'payload.data');

        if (is_string($data) && $data !== '') {
            $write($data);
        }
    }

    private function lines(): ?int
    {
        $value = $this->option('lines');

        if (! is_numeric($value)) {
            return null;
        }

        $lines = (int) $value;

        return $lines > 0 ? $lines : null;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderLogLines(array $response): void
    {
        $data = $this->successData($response);
        $logs = is_array($data['logs'] ?? null) ? $data['logs'] : [];
        $lines = is_array($logs['lines'] ?? null) ? $logs['lines'] : [];

        if ($lines === []) {
            $this->line('No log lines found.');

            return;
        }

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $timestamp = is_string($line['timestamp'] ?? null) && trim($line['timestamp']) !== ''
                ? trim($line['timestamp']).' '
                : '';

            $this->line($timestamp.(string) ($line['message'] ?? ''));
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function successData(array $response): array
    {
        $success = $response['success'] ?? null;

        if (is_array($success) && is_array($success['data'] ?? null)) {
            return $success['data'];
        }

        return $response;
    }
}
