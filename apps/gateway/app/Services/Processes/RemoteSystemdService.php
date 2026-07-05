<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Data\Convergence\ConvergenceApplyResult;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Convergence\ConvergenceStatus;
use App\Models\Node;
use App\Services\RemoteShell\RemoteLocalExecutor;
use JsonException;

/**
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class RemoteSystemdService
{
    public function __construct(
        private RemoteLocalExecutor $localExecutor,
    ) {}

    public function apply(Node $node, string $service, string $content, bool $enabled = true): ConvergenceApplyResult
    {
        $result = $this->run(
            node: $node,
            action: 'apply',
            service: $service,
            input: json_encode([
                'content' => $content,
                'enabled' => $enabled,
            ], JSON_THROW_ON_ERROR),
        );

        if (! $result->successful()) {
            return new ConvergenceApplyResult(
                status: ConvergenceStatus::Failed,
                summary: "Failed to apply systemd service {$service}.",
                details: [
                    'service' => $service,
                    'exit_code' => $result->exitCode,
                    'error' => $result->errorOutput() !== '' ? $result->errorOutput() : null,
                ],
            );
        }

        $data = $this->successData($result);
        $status = ($data['status'] ?? null) === 'ok'
            ? ConvergenceStatus::Ok
            : ConvergenceStatus::Changed;
        $summary = "Applied systemd service {$service}.";
        $details = [];

        if (array_key_exists('summary', $data) && is_string($data['summary'])) {
            $summary = $data['summary'];
        }

        if (array_key_exists('details', $data) && is_array($data['details'])) {
            $details = $this->stringKeyed($data['details']);
        }

        return new ConvergenceApplyResult(
            status: $status,
            summary: $summary,
            details: $details,
        );
    }

    public function start(Node $node, string $service): bool
    {
        return $this->run($node, 'start', $service)->successful();
    }

    public function stop(Node $node, string $service): bool
    {
        return $this->run($node, 'stop', $service)->successful();
    }

    public function restart(Node $node, string $service): bool
    {
        return $this->run($node, 'restart', $service)->successful();
    }

    public function remove(Node $node, string $service, string $unitPath): bool
    {
        return $this->run($node, 'remove', $service, $unitPath)->successful();
    }

    private function run(
        Node $node,
        string $action,
        string $service,
        ?string $unitPath = null,
        ?string $input = null,
    ): RemoteShellResult {
        $arguments = [
            $action,
            $service,
        ];

        if ($unitPath !== null) {
            $arguments[] = $unitPath;
        }

        $transportOptions = [
            'metadata' => [
                'ORBIT_OPERATION_ID' => "process.systemd.{$action}",
            ],
            'timeout' => 60,
        ];

        if ($input !== null) {
            $transportOptions['input'] = $input;
        }

        return $this->localExecutor->runInternal(
            node: $node,
            commandName: 'internal:process-systemd-service',
            arguments: $arguments,
            transportOptions: $transportOptions,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function successData(RemoteShellResult $result): array
    {
        try {
            /** @var mixed $payload */
            $payload = json_decode($result->stdout, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($payload)) {
            return [];
        }

        if (! array_key_exists('success', $payload) || ! is_array($payload['success'])) {
            return [];
        }

        if (! array_key_exists('data', $payload['success']) || ! is_array($payload['success']['data'])) {
            return [];
        }

        return $this->stringKeyed($payload['success']['data']);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<string, mixed>
     */
    private function stringKeyed(array $payload): array
    {
        foreach (array_keys($payload) as $key) {
            if (! is_string($key)) {
                return [];
            }
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
