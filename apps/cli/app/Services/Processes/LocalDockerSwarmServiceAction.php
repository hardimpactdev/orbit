<?php

declare(strict_types=1);

namespace App\Services\Processes;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process as ProcessFacade;
use Symfony\Component\Process\Process;

final readonly class LocalDockerSwarmServiceAction
{
    private const array ACTIONS = ['apply', 'ensure', 'remove', 'restart', 'start', 'stop'];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function run(string $action, string $service, array $payload = []): array
    {
        $action = $this->action($action);
        $service = $this->service($service);

        if ($action === 'ensure') {
            return $this->ensureManager($service, $payload);
        }

        if ($action === 'apply') {
            return $this->apply(LocalDockerSwarmServiceSpec::from([
                ...$payload,
                'name' => $service,
            ]));
        }

        $result = $this->runProcess($this->command($action, $service));

        if ($result->isSuccessful()) {
            return [
                'action' => $action,
                'service' => $service,
                'changed' => true,
            ];
        }

        throw $this->failure($action, $service, $result);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function ensureManager(string $service, array $payload): array
    {
        $advertiseAddress = $this->advertiseAddress($payload);
        $inspect = $this->runEnsureProcess(['docker', 'info', '--format', '{{.Swarm.LocalNodeState}}']);

        if (! $inspect->successful()) {
            throw $this->ensureFailure($service, $inspect);
        }

        $state = trim($inspect->output());

        if ($state === 'active') {
            $control = $this->runEnsureProcess(['docker', 'info', '--format', '{{.Swarm.ControlAvailable}}']);

            if (! $control->successful()) {
                throw $this->ensureFailure($service, $control);
            }

            if (trim($control->output()) !== 'true') {
                throw new LocalDockerSwarmServiceFailure(
                    errorCode: 'docker_swarm_service.ensure_failed',
                    message: "Docker Swarm is active on '{$service}', but this node is not a Swarm manager.",
                    meta: [
                        'action' => 'ensure',
                        'service' => $service,
                        'state' => $state,
                    ],
                );
            }

            return [
                'action' => 'ensure',
                'service' => $service,
                'changed' => false,
            ];
        }

        if ($state !== 'inactive') {
            throw new LocalDockerSwarmServiceFailure(
                errorCode: 'docker_swarm_service.ensure_failed',
                message: "Docker Swarm local node state '{$state}' is not supported.",
                meta: [
                    'action' => 'ensure',
                    'service' => $service,
                    'state' => $state,
                ],
            );
        }

        $initialize = $this->runEnsureProcess([
            'docker',
            'swarm',
            'init',
            '--advertise-addr',
            $advertiseAddress,
        ]);

        if (! $initialize->successful()) {
            throw $this->ensureFailure($service, $initialize);
        }

        return [
            'action' => 'ensure',
            'service' => $service,
            'changed' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function advertiseAddress(array $payload): string
    {
        if (
            isset($payload['advertise_address'])
            && is_string($payload['advertise_address'])
            && filter_var($payload['advertise_address'], FILTER_VALIDATE_IP) !== false
        ) {
            return $payload['advertise_address'];
        }

        throw new LocalDockerSwarmServiceFailure(
            errorCode: 'validation_failed',
            message: 'Docker Swarm advertise address is invalid.',
            meta: ['field' => 'advertise_address'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function apply(LocalDockerSwarmServiceSpec $spec): array
    {
        $inspect = $this->runProcess([
            'docker',
            'service',
            'inspect',
            '--format',
            '{{ index .Spec.Labels "orbit.process.spec_hash" }}',
            $spec->name,
        ]);
        $hadExistingService = $inspect->isSuccessful();

        if ($hadExistingService && hash_equals($spec->expectedHash, trim($inspect->getOutput()))) {
            return [
                'action' => 'apply',
                'service' => $spec->name,
                'changed' => false,
                'outcome' => 'unchanged',
            ];
        }

        if ($hadExistingService) {
            $remove = $this->runProcess(['docker', 'service', 'rm', $spec->name]);

            if (! $remove->isSuccessful()) {
                throw $this->applyFailure('remove drifted', $spec, $remove, true);
            }
        }

        $create = $this->runProcess($spec->createCommand());

        if (! $create->isSuccessful()) {
            throw $this->applyFailure('create', $spec, $create, $hadExistingService);
        }

        return [
            'action' => 'apply',
            'service' => $spec->name,
            'changed' => true,
            'outcome' => $hadExistingService ? 'recreated' : 'created',
        ];
    }

    /**
     * @return list<string>
     */
    private function command(string $action, string $service): array
    {
        return match ($action) {
            'remove' => ['docker', 'service', 'rm', $service],
            'restart' => ['docker', 'service', 'update', '--detach', '--force', $service],
            'start' => ['docker', 'service', 'update', '--detach', '--replicas', '1', $service],
            'stop' => ['docker', 'service', 'update', '--detach', '--replicas', '0', $service],
            default => throw new LocalDockerSwarmServiceFailure(
                errorCode: 'validation_failed',
                message: 'Docker Swarm service action is invalid.',
                meta: ['field' => 'action'],
            ),
        };
    }

    private function action(string $value): string
    {
        if (in_array($value, self::ACTIONS, strict: true)) {
            return $value;
        }

        throw new LocalDockerSwarmServiceFailure(
            errorCode: 'validation_failed',
            message: 'Docker Swarm service action is invalid.',
            meta: ['field' => 'action'],
        );
    }

    private function service(string $value): string
    {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $value) === 1) {
            return $value;
        }

        throw new LocalDockerSwarmServiceFailure(
            errorCode: 'validation_failed',
            message: 'Docker Swarm service name is invalid.',
            meta: ['field' => 'service'],
        );
    }

    /**
     * @param  list<string>  $command
     */
    private function runProcess(array $command): Process
    {
        $process = new Process($command);
        $process->setTimeout(60);
        $process->run();

        return $process;
    }

    /**
     * @param  list<string>  $command
     */
    private function runEnsureProcess(array $command): ProcessResult
    {
        return ProcessFacade::timeout(60)->run($command);
    }

    private function failure(string $action, string $service, Process $result): LocalDockerSwarmServiceFailure
    {
        return new LocalDockerSwarmServiceFailure(
            errorCode: "docker_swarm_service.{$action}_failed",
            message: "Docker Swarm service {$action} failed for '{$service}'.",
            meta: [
                'action' => $action,
                'service' => $service,
                'exit_code' => $result->getExitCode(),
                'stderr' => trim($result->getErrorOutput()),
            ],
        );
    }

    private function ensureFailure(string $service, ProcessResult $result): LocalDockerSwarmServiceFailure
    {
        return new LocalDockerSwarmServiceFailure(
            errorCode: 'docker_swarm_service.ensure_failed',
            message: "Docker Swarm manager initialization failed for '{$service}'.",
            meta: [
                'action' => 'ensure',
                'service' => $service,
                'exit_code' => $result->exitCode(),
                'stderr' => trim($result->errorOutput()),
            ],
        );
    }

    private function applyFailure(
        string $step,
        LocalDockerSwarmServiceSpec $spec,
        Process $result,
        bool $hadExistingService,
    ): LocalDockerSwarmServiceFailure {
        $output = trim($result->getErrorOutput().' '.$result->getOutput());
        $message = $output !== '' ? $output : 'unknown error';

        return new LocalDockerSwarmServiceFailure(
            errorCode: 'docker_swarm_service.apply_failed',
            message: "Failed to {$step} {$spec->name} Docker Swarm service: {$message}",
            meta: [
                'action' => 'apply',
                'service' => $spec->name,
                'had_existing_service' => $hadExistingService,
                'exit_code' => $result->getExitCode(),
                'stderr' => trim($result->getErrorOutput()),
            ],
        );
    }
}
