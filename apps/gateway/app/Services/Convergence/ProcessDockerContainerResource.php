<?php

declare(strict_types=1);

namespace App\Services\Convergence;

use App\Contracts\RemoteShell;
use App\Data\Convergence\ConvergenceApplyResult;
use App\Data\Convergence\ProcessDockerContainerPlan;
use App\Data\Convergence\ProcessDockerContainerProbe;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Convergence\ConvergenceStatus;
use App\Enums\Processes\ProcessDockerContainerApplyOutcome;
use App\Models\Node;
use App\Services\Processes\ProcessDockerContainer;
use App\Services\RemoteShell\RemoteLocalExecutor;
use JsonException;
use RuntimeException;

final readonly class ProcessDockerContainerResource
{
    public function __construct(
        private ProcessDockerContainer $container,
        private RemoteLocalExecutor $localExecutor,
    ) {}

    public function ensureNetwork(Node $node, RemoteShell $remoteShell): void
    {
        $result = $this->runAction($node, 'ensure-network');

        if ($result->successful()) {
            return;
        }

        throw new RuntimeException($this->failureMessage(
            $node,
            "create {$this->container->network()} Docker network",
            $result,
        ));
    }

    public function probe(Node $node, RemoteShell $remoteShell): ProcessDockerContainerProbe
    {
        $result = $this->runAction($node, 'probe');

        if (! $result->successful()) {
            return new ProcessDockerContainerProbe(
                reachable: true,
                exists: false,
                error: trim($result->stderr) !== '' ? trim($result->stderr) : null,
            );
        }

        $data = $this->successData($result);
        $exists = $data['exists'] ?? false;

        if ($exists !== true) {
            return new ProcessDockerContainerProbe(
                reachable: true,
                exists: false,
            );
        }

        $inspection = $data['inspection'] ?? null;
        if (! is_array($inspection)) {
            throw new RuntimeException(
                "Docker returned an invalid inspect payload for {$this->container->name()} on {$node->name}.",
            );
        }

        return new ProcessDockerContainerProbe(
            reachable: true,
            exists: true,
            specHash: $this->stringValue($data['spec_hash'] ?? null),
            inspection: $inspection,
        );
    }

    public function plan(ProcessDockerContainerProbe $probe): ProcessDockerContainerPlan
    {
        if (! $probe->reachable) {
            return new ProcessDockerContainerPlan(
                status: ConvergenceStatus::Unreachable,
                summary: "Could not inspect Docker process container {$this->container->name()}.",
                outcome: ProcessDockerContainerApplyOutcome::Unchanged,
                details: $this->details(['error' => $probe->error]),
            );
        }

        if (! $probe->exists) {
            return new ProcessDockerContainerPlan(
                status: ConvergenceStatus::Changed,
                summary: "Create Docker process container {$this->container->name()}.",
                outcome: ProcessDockerContainerApplyOutcome::Created,
                details: $this->details([
                    'observed_hash' => null,
                    'outcome' => ProcessDockerContainerApplyOutcome::Created->value,
                ]),
            );
        }

        if (! hash_equals($this->container->specHash(), $probe->specHash ?? '')) {
            return new ProcessDockerContainerPlan(
                status: ConvergenceStatus::Changed,
                summary: "Recreate Docker process container {$this->container->name()}.",
                outcome: ProcessDockerContainerApplyOutcome::Recreated,
                details: $this->details([
                    'observed_hash' => $probe->specHash,
                    'outcome' => ProcessDockerContainerApplyOutcome::Recreated->value,
                ]),
            );
        }

        return new ProcessDockerContainerPlan(
            status: ConvergenceStatus::Ok,
            summary: "Docker process container {$this->container->name()} already matches gateway intent.",
            outcome: ProcessDockerContainerApplyOutcome::Unchanged,
            details: $this->details([
                'observed_hash' => $probe->specHash,
                'outcome' => ProcessDockerContainerApplyOutcome::Unchanged->value,
            ]),
        );
    }

    public function apply(
        Node $node,
        RemoteShell $remoteShell,
        ProcessDockerContainerPlan $plan,
    ): ConvergenceApplyResult {
        if (! $plan->shouldApply()) {
            return new ConvergenceApplyResult(
                status: $plan->status,
                summary: $plan->summary,
                details: $plan->details,
            );
        }

        $result = $this->runAction($node, 'apply');

        if (! $result->successful()) {
            return $this->failedResult($node, "apply {$this->container->name()} container", $result, $plan);
        }

        $data = $this->successData($result);
        $changed = ($data['changed'] ?? true) === true;

        return new ConvergenceApplyResult(
            status: $changed ? ConvergenceStatus::Changed : ConvergenceStatus::Ok,
            summary: $this->stringValue($data['summary'] ?? null)
            ?? "Applied Docker process container {$this->container->name()}.",
            details: $this->details([
                'outcome' => $this->stringValue($data['outcome'] ?? null) ?? $plan->outcome->value,
            ]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function successData(RemoteShellResult $result): array
    {
        $payload = $this->jsonPayload($result->stdout);
        /** @var mixed $success */
        $success = $payload['success'] ?? null;
        /** @var mixed $data */
        $data = is_array($success) ? $success['data'] ?? null : null;

        if (is_array($data) && $this->hasOnlyStringKeys($data)) {
            /** @var array<string, mixed> $stringData */
            $stringData = $data;

            return $stringData;
        }

        throw new RuntimeException('Process Docker container response is invalid.');
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonPayload(string $stdout): array
    {
        try {
            /** @var mixed $payload */
            $payload = json_decode($stdout, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($payload) || ! $this->hasOnlyStringKeys($payload)) {
            return [];
        }

        /** @var array<string, mixed> $stringPayload */
        $stringPayload = $payload;

        return $stringPayload;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function hasOnlyStringKeys(array $payload): bool
    {
        return array_all(array_keys($payload), fn ($key) => is_string($key));
    }

    private function failedResult(
        Node $node,
        string $step,
        RemoteShellResult $result,
        ProcessDockerContainerPlan $plan,
    ): ConvergenceApplyResult {
        return new ConvergenceApplyResult(
            status: ConvergenceStatus::Failed,
            summary: $this->failureMessage($node, $step, $result),
            details: $this->details([
                'outcome' => $plan->outcome->value,
                'exit_code' => $result->exitCode,
                'error' => trim($result->stderr) !== '' ? trim($result->stderr) : null,
            ]),
        );
    }

    private function failureMessage(Node $node, string $step, RemoteShellResult $result): string
    {
        $output = trim($result->errorOutput().' '.$this->failureEnvelopeMessage($result));
        $message = $output !== '' ? $output : 'unknown error';

        return "Failed to {$step} on {$node->name}: {$message}";
    }

    private function failureEnvelopeMessage(RemoteShellResult $result): string
    {
        $payload = $this->jsonPayload($result->stdout);
        /** @var mixed $error */
        $error = $payload['error'] ?? null;

        if (! is_array($error)) {
            return $result->stdout;
        }

        /** @var mixed $message */
        $message = $error['message'] ?? null;

        return is_string($message) ? $message : $result->stdout;
    }

    private function runAction(Node $node, string $action): RemoteShellResult
    {
        $payload = [
            'action' => $action,
            'spec' => [
                ...$this->container->spec(),
                'expected_hash' => $this->container->specHash(),
            ],
        ];

        return $this->localExecutor->runInternal(
            node: $node,
            commandName: 'internal:process-docker-container',
            transportOptions: [
                'input' => json_encode($payload, JSON_THROW_ON_ERROR),
                'metadata' => [
                    'ORBIT_OPERATION_ID' => "process.docker.{$action}",
                ],
                'strict' => false,
                'timeout' => 120,
            ],
        );
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function details(array $extra = []): array
    {
        return [
            'container' => $this->container->name(),
            'network' => $this->container->network(),
            'expected_hash' => $this->container->specHash(),
            ...array_filter($extra, fn (mixed $value): bool => $value !== null),
        ];
    }
}
