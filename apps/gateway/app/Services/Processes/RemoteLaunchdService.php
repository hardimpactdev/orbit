<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Data\Convergence\ConvergenceApplyResult;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Convergence\ConvergenceStatus;
use App\Models\Node;
use App\Services\RemoteShell\Exceptions\RemoteShellProtocolException;
use App\Services\RemoteShell\RemoteShellSuccessData;
use App\Services\RemoteShell\RunsInternalCommands;

final readonly class RemoteLaunchdService
{
    public function __construct(
        private RunsInternalCommands $localExecutor,
    ) {}

    public function apply(Node $node, string $label, string $content, bool $enabled = true): ConvergenceApplyResult
    {
        $result = $this->run(
            node: $node,
            action: 'apply',
            label: $label,
            input: json_encode([
                'content' => $content,
                'enabled' => $enabled,
            ], JSON_THROW_ON_ERROR),
        );

        if (! $result->successful()) {
            return new ConvergenceApplyResult(
                status: ConvergenceStatus::Failed,
                summary: "Failed to apply launchd service {$label}.",
                details: [
                    'label' => $label,
                    'exit_code' => $result->exitCode,
                    'error' => $result->errorOutput() !== '' ? $result->errorOutput() : null,
                ],
            );
        }

        try {
            $data = RemoteShellSuccessData::fromJsonEnvelopeOrFail($result);
        } catch (RemoteShellProtocolException $exception) {
            return new ConvergenceApplyResult(
                status: ConvergenceStatus::Failed,
                summary: "Failed to apply launchd service {$label}.",
                details: [
                    'label' => $label,
                    'error' => "Apply returned an invalid success envelope: {$exception->getMessage()}",
                ],
            );
        }

        $status = ($data['status'] ?? null) === 'ok'
            ? ConvergenceStatus::Ok
            : ConvergenceStatus::Changed;
        $summary = "Applied launchd service {$label}.";
        $details = [];

        if (array_key_exists('summary', $data) && is_string($data['summary'])) {
            $summary = $data['summary'];
        }

        if (array_key_exists('details', $data) && is_array($data['details'])) {
            /** @var array<string, mixed> $details */
            $details = $data['details'];
        }

        return new ConvergenceApplyResult(
            status: $status,
            summary: $summary,
            details: $details,
        );
    }

    public function remove(Node $node, string $label, ?string $plistPath = null): bool
    {
        $result = $this->run(
            node: $node,
            action: 'remove',
            label: $label,
            plistPath: $plistPath,
        );

        return $result->successful();
    }

    public function start(Node $node, string $label): bool
    {
        return $this->run($node, 'start', $label)->successful();
    }

    public function stop(Node $node, string $label): bool
    {
        return $this->run($node, 'stop', $label)->successful();
    }

    public function restart(Node $node, string $label): bool
    {
        return $this->run($node, 'restart', $label)->successful();
    }

    public function isActive(Node $node, string $label): bool
    {
        return $this->run($node, 'is-active', $label)->successful();
    }

    private function run(
        Node $node,
        string $action,
        string $label,
        ?string $plistPath = null,
        ?string $input = null,
    ): RemoteShellResult {
        $arguments = [
            $action,
            $label,
        ];

        if ($plistPath !== null) {
            $arguments[] = $plistPath;
        }

        $transportOptions = [
            'metadata' => [
                'ORBIT_OPERATION_ID' => "process.launchd.{$action}",
            ],
            'timeout' => 60,
        ];

        if ($input !== null) {
            $transportOptions['input'] = $input;
        }

        return $this->localExecutor->runInternal(
            node: $node,
            commandName: 'internal:process-launchd-service',
            arguments: $arguments,
            transportOptions: $transportOptions,
        );
    }
}
