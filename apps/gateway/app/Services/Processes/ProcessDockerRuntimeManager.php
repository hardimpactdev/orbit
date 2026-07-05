<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Processes\ProcessDockerContainerApplyOutcome;
use App\Exceptions\ProcessDockerContainerApplyException;
use App\Models\Node;
use App\Services\RemoteShell\RemoteLocalExecutor;
use JsonException;
use RuntimeException;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:too-many-methods
 */
final readonly class ProcessDockerRuntimeManager
{
    public function __construct(
        private RemoteLocalExecutor $localExecutor,
    ) {}

    /**
     * Converge the rendered process runtime artifact on the node.
     *
     * The container is created in Docker's `Created` state (i.e. not
     * running). The contract from process:add is that `--start` controls
     * whether the rendered unit starts; apply only converges the artifact
     * shape. Lifecycle commands (process:start / --start / --restart) are
     * the only callers that flip the container into the running state.
     */
    public function apply(
        Node $node,
        ProcessDockerContainer $container,
        bool $preparePrerequisites = false,
    ): ProcessDockerContainerApplyOutcome {
        $result = $this->runAction($node, 'apply', null, [
            'prepare_prerequisites' => $preparePrerequisites,
            'spec' => [
                ...$container->spec(),
                'expected_hash' => $container->specHash(),
            ],
        ]);

        if ($result->successful()) {
            return $this->applyOutcome($result);
        }

        $failure = $this->failureEnvelope($result);

        throw new ProcessDockerContainerApplyException(
            hadExistingContainer: $failure['had_existing_container'],
            message: $failure['message'],
        );
    }

    public function remove(Node $node, string $containerName): bool
    {
        return $this->runAction($node, 'remove', $containerName)->successful();
    }

    /**
     * Lifecycle hook used by AddProcess --start / EditProcess --restart.
     * Returns true when the lifecycle command succeeded.
     */
    public function start(Node $node, string $containerName): bool
    {
        return $this->runAction($node, 'start', $containerName)->successful();
    }

    public function stop(Node $node, string $containerName): bool
    {
        return $this->runAction($node, 'stop', $containerName)->successful();
    }

    public function restart(Node $node, string $containerName): bool
    {
        return $this->runAction($node, 'restart', $containerName)->successful();
    }

    /**
     * @param  array<string, mixed>  $extraPayload
     */
    private function runAction(
        Node $node,
        string $action,
        ?string $containerName = null,
        array $extraPayload = [],
    ): RemoteShellResult {
        $payload = [
            'action' => $action,
            ...$extraPayload,
        ];

        if ($containerName !== null) {
            $payload['container'] = $containerName;
        }

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

    private function applyOutcome(RemoteShellResult $result): ProcessDockerContainerApplyOutcome
    {
        $data = $this->successData($result);
        /** @var mixed $outcome */
        $outcome = $data['outcome'] ?? null;

        if (! is_string($outcome)) {
            throw new RuntimeException('Process Docker apply response is missing an outcome.');
        }

        return ProcessDockerContainerApplyOutcome::from($outcome);
    }

    /**
     * @return array{message: string, had_existing_container: bool}
     */
    private function failureEnvelope(RemoteShellResult $result): array
    {
        $payload = $this->jsonPayload($result->stdout);
        /** @var mixed $error */
        $error = $payload['error'] ?? null;

        if (! is_array($error)) {
            return [
                'message' => $result->errorOutput() !== ''
                    ? $result->errorOutput()
                    : 'Docker process container apply failed.',
                'had_existing_container' => false,
            ];
        }

        /** @var mixed $message */
        $message = $error['message'] ?? null;
        /** @var mixed $meta */
        $meta = $error['meta'] ?? [];
        /** @var mixed $hadExistingContainer */
        $hadExistingContainer = is_array($meta) ? $meta['had_existing_container'] ?? false : false;

        return [
            'message' => is_string($message) && trim($message) !== ''
                ? $message
                : 'Docker process container apply failed.',
            'had_existing_container' => is_bool($hadExistingContainer) ? $hadExistingContainer : false,
        ];
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

        throw new RuntimeException('Process Docker apply response is invalid.');
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
}
