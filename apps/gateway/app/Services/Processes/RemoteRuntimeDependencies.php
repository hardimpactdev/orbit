<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Data\RemoteShell\RemoteShellResult;
use App\Services\RemoteShell\Exceptions\RemoteShellProtocolException;
use App\Services\RemoteShell\RemoteShellSuccessData;
use App\Services\RemoteShell\RunsInternalCommands;
use Orbit\Core\Enums\InternalCommand;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class RemoteRuntimeDependencies
{
    public function __construct(
        private RunsInternalCommands $localExecutor,
    ) {}

    /**
     * @return array{
     *     source_activity_at: int,
     *     dependencies: list<array{
     *         key: string,
     *         label: string,
     *         present: bool,
     *         reconstructable: bool
     *     }>
     * }|null
     */
    public function inspect(RuntimeHibernationScope $scope): ?array
    {
        $path = $scope->sourcePath();

        if ($path === null) {
            return null;
        }

        $data = $this->successData($this->run($scope, 'inspect', $path));

        if (! is_array($data)) {
            return null;
        }

        if (
            ! is_int($data['source_activity_at'] ?? null)
            || ! is_array($data['dependencies'] ?? null)
        ) {
            return null;
        }

        $sourceActivityAt = $data['source_activity_at'];
        $rawDependencies = $data['dependencies'];
        $dependencies = [];

        /** @mago-expect analyzer:mixed-assignment */
        foreach ($rawDependencies as $dependency) {
            if (
                ! is_array($dependency)
                || ! is_string($dependency['key'] ?? null)
                || ! is_string($dependency['label'] ?? null)
                || ! is_bool($dependency['present'] ?? null)
                || ! is_bool($dependency['reconstructable'] ?? null)
            ) {
                return null;
            }

            $dependencies[] = [
                'key' => $dependency['key'],
                'label' => $dependency['label'],
                'present' => $dependency['present'],
                'reconstructable' => $dependency['reconstructable'],
            ];
        }

        return [
            'source_activity_at' => $sourceActivityAt,
            'dependencies' => $dependencies,
        ];
    }

    public function prune(RuntimeHibernationScope $scope): bool
    {
        $path = $scope->sourcePath();

        return $path !== null && $this->run($scope, 'prune', $path)->successful();
    }

    public function restore(RuntimeHibernationScope $scope, string $family): bool
    {
        $path = $scope->sourcePath();

        return $path !== null && $this->run($scope, 'restore', $path, $family)->successful();
    }

    public function restoreIfMissing(RuntimeHibernationScope $scope, string $family): bool
    {
        $state = $this->inspect($scope);

        if (! is_array($state)) {
            return false;
        }

        foreach ($state['dependencies'] as $dependency) {
            if ($dependency['key'] !== $family) {
                continue;
            }

            if ($dependency['present']) {
                return true;
            }

            return $dependency['reconstructable'] && $this->restore($scope, $family);
        }

        return false;
    }

    public function ready(RuntimeHibernationScope $scope): bool
    {
        $state = $this->inspect($scope);

        return (
            is_array($state)
            && array_all(
                $state['dependencies'],
                static fn (array $dependency): bool => $dependency['present'] || ! $dependency['reconstructable'],
            )
        );
    }

    private function run(
        RuntimeHibernationScope $scope,
        string $action,
        string $path,
        ?string $family = null,
    ): RemoteShellResult {
        $arguments = [$action, $path];

        if ($family !== null) {
            $arguments[] = $family;
        }

        return $this->localExecutor->runInternal(
            node: $scope->node,
            commandName: InternalCommand::RuntimeDependencies->value,
            arguments: $arguments,
            transportOptions: [
                'metadata' => [
                    'ORBIT_OPERATION_ID' => "runtime-dependencies.{$scope->key()}.{$action}",
                ],
                'redact_stdout' => true,
                'redact_stderr' => true,
                'timeout' => $action === 'restore' ? 900 : 120,
                'throw' => false,
            ],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function successData(RemoteShellResult $result): ?array
    {
        if (! $result->successful()) {
            return null;
        }

        try {
            return RemoteShellSuccessData::fromJsonEnvelopeOrFail($result);
        } catch (RemoteShellProtocolException) {
            return null;
        }
    }
}
