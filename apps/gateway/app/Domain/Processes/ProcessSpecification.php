<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Data\Processes\AddProcessData;
use App\Domain\DatabaseConnections\DockerPublishedPort;
use App\Domain\Instances\InstanceDevPorts;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Process;
use SensitiveParameter;

final readonly class ProcessSpecification
{
    public function assertOptions(AddProcessData $data): void
    {
        $paths = RequiredMountPaths::parse($data->requiredMountPaths);
        if ($paths !== [] && ($data->runtime !== ProcessRuntime::Systemd || $data->preset !== null)) {
            throw new ResourceOperationException('process.option_invalid', 'Required mount paths require an ordinary systemd Process.');
        }
        if ($data->user !== null && ($data->targetType !== ProcessTargetType::Node || $data->runtime !== ProcessRuntime::Systemd || $data->preset !== null)) {
            throw new ResourceOperationException('process.option_invalid', 'The user option requires a Node systemd Process without a preset.');
        }
    }

    /** @return array{runtime: ProcessRuntime, working_directory: ?string, runtime_config: array<string, mixed>, restart_policy: string, keep_alive: bool, restart_on_deploy: bool} */
    public function attributes(#[SensitiveParameter] AddProcessData $data, ProcessTarget $target): array
    {
        // A container's Processes are `orbit` units in it: no Docker, and no preset that names Node paths.
        if ($target->container !== null && ($data->runtime !== ProcessRuntime::Systemd || $data->preset !== null)) {
            throw new ResourceOperationException('process.target_unsupported', 'A Process in an Instance container must be an ordinary systemd Process.', 409);
        }
        // A TCP publication on every address or on the Instance bridge address must stay out of the containers' development band.
        foreach ($data->runtime === ProcessRuntime::Docker ? $data->ports : [] as $spec) {
            $mapping = str_contains($spec, '/') && ! str_ends_with($spec, '/tcp') ? null : DockerPublishedPort::parse($spec);
            if ($mapping !== null) {
                InstanceDevPorts::assertOutsideBand($target->node, $mapping);
            }
        }
        $workingDirectory =
            $data->workingDirectory
            ?? ($data->runtime === ProcessRuntime::Systemd ? $target->defaultWorkingDirectory : null);
        $runtimeConfig = $data->runtime === ProcessRuntime::Systemd
            ? [
                'command' => $data->command,
                'environment_file' => $target->environmentFile,
                ...($data->environment === [] ? [] : ['environment' => $data->environment]),
                ...($data->preset === null ? [] : ['preset' => $data->preset]),
                ...($data->user === null ? [] : ['user' => $data->user]),
                ...($data->requiredMountPaths === [] ? [] : ['required_mount_paths' => RequiredMountPaths::parse($data->requiredMountPaths)]),
            ]
            : [
                'image' => $data->image,
                'command' => $data->command,
                'environment' => $data->environment,
                'ports' => $data->ports,
                'volumes' => $data->volumes,
            ];

        return [
            'runtime' => $data->runtime,
            'working_directory' => $workingDirectory,
            'runtime_config' => $this->canonicalRuntimeConfig($runtimeConfig),
            'restart_policy' => $data->restartPolicy,
            'keep_alive' => $data->keepAlive,
            'restart_on_deploy' => $data->restartOnDeploy,
        ];
    }

    /** @param array{runtime: ProcessRuntime, working_directory: ?string, runtime_config: array<string, mixed>, restart_policy: string, keep_alive: bool, restart_on_deploy: bool} $attributes */
    public function matches(
        #[SensitiveParameter]
        Process $process,
        #[SensitiveParameter]
        array $attributes,
    ): bool {
        return
            $process->runtime === $attributes['runtime']
            && $process->working_directory === $attributes['working_directory']
            && $this->canonicalRuntimeConfig($process->runtime_config)
            === $attributes['runtime_config']
            && $process->restart_policy === $attributes['restart_policy']
            && $process->keep_alive === $attributes['keep_alive']
            && $process->restart_on_deploy === $attributes['restart_on_deploy'];
    }

    /**
     * @param  array<string, mixed>  $runtimeConfig
     * @return array<string, mixed>
     */
    private function canonicalRuntimeConfig(
        #[SensitiveParameter]
        array $runtimeConfig,
    ): array {
        if (! array_key_exists('environment', $runtimeConfig)) {
            return $runtimeConfig;
        }

        $environment = $runtimeConfig['environment'];

        if (! is_array($environment)) {
            return $runtimeConfig;
        }

        ksort($environment);
        $runtimeConfig['environment'] = $environment;

        return $runtimeConfig;
    }
}
