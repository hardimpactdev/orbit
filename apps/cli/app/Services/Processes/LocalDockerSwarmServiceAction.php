<?php

declare(strict_types=1);

namespace App\Services\Processes;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process as ProcessFacade;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:too-many-methods
 * @mago-expect lint:kan-defect
 */
final readonly class LocalDockerSwarmServiceAction
{
    private const array ACTIONS = ['apply', 'ensure', 'is-active', 'remove', 'restart', 'start', 'stop'];

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

        if ($action === 'is-active') {
            if ($this->isActivelyRunning($result)) {
                return [
                    'action' => $action,
                    'service' => $service,
                    'changed' => false,
                ];
            }

            throw $this->failure($action, $service, $result);
        }

        if ($result->successful()) {
            return [
                'action' => $action,
                'service' => $service,
                'changed' => true,
            ];
        }

        if ($action === 'remove' && $this->serviceIsAbsent($result)) {
            return [
                'action' => $action,
                'service' => $service,
                'changed' => false,
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
            ! array_key_exists('advertise_address', $payload)
            || ! is_string($payload['advertise_address'])
            || filter_var($payload['advertise_address'], FILTER_VALIDATE_IP) === false
        ) {
            throw new LocalDockerSwarmServiceFailure(
                errorCode: 'validation_failed',
                message: 'Docker Swarm advertise address is invalid.',
                meta: ['field' => 'advertise_address'],
            );
        }

        return $payload['advertise_address'];
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
        $hadExistingService = $inspect->successful();

        if ($hadExistingService && hash_equals($spec->expectedHash, trim($inspect->output()))) {
            return [
                'action' => 'apply',
                'service' => $spec->name,
                'changed' => false,
                'outcome' => 'unchanged',
            ];
        }

        if ($hadExistingService) {
            $remove = $this->runProcess(['docker', 'service', 'rm', $spec->name]);

            if (! $remove->successful()) {
                throw $this->applyFailure('remove drifted', $spec, $remove, true);
            }
        }

        $create = $this->runProcess($spec->createCommand());

        if (! $create->successful()) {
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
            'is-active' => [
                'docker',
                'service',
                'inspect',
                '--format',
                '{{if .ServiceStatus}}{{.ServiceStatus.RunningTasks}} {{.ServiceStatus.DesiredTasks}}{{else}}0 0{{end}}',
                $service,
            ],
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
    private function runProcess(array $command): ProcessResult
    {
        return ProcessFacade::timeout(60)->run($command);
    }

    /**
     * @param  list<string>  $command
     */
    private function runEnsureProcess(array $command): ProcessResult
    {
        return ProcessFacade::timeout(60)->run($command);
    }

    private function serviceIsAbsent(ProcessResult $result): bool
    {
        return str_contains(
            strtolower($result->errorOutput().' '.$result->output()),
            'no such service',
        );
    }

    private function isActivelyRunning(ProcessResult $result): bool
    {
        if (! $result->successful()) {
            return false;
        }

        $parts = preg_split('/\s+/', trim($result->output()));

        if (! is_array($parts)) {
            return false;
        }

        $runningToken = $parts[0] ?? null;
        $desiredToken = $parts[1] ?? null;
        $running = is_string($runningToken) && ctype_digit($runningToken) ? (int) $runningToken : 0;
        $desired = is_string($desiredToken) && ctype_digit($desiredToken) ? (int) $desiredToken : 0;

        return $desired > 0 && $running >= $desired;
    }

    private function failure(string $action, string $service, ProcessResult $result): LocalDockerSwarmServiceFailure
    {
        return new LocalDockerSwarmServiceFailure(
            errorCode: "docker_swarm_service.{$action}_failed",
            message: "Docker Swarm service {$action} failed for '{$service}'.",
            meta: [
                'action' => $action,
                'service' => $service,
                'exit_code' => $result->exitCode(),
                'stderr' => trim($result->errorOutput()),
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
        ProcessResult $result,
        bool $hadExistingService,
    ): LocalDockerSwarmServiceFailure {
        $output = trim($result->errorOutput().' '.$result->output());
        $message = $output !== '' ? $output : 'unknown error';

        return new LocalDockerSwarmServiceFailure(
            errorCode: 'docker_swarm_service.apply_failed',
            message: "Failed to {$step} {$spec->name} Docker Swarm service: {$message}",
            meta: [
                'action' => 'apply',
                'service' => $spec->name,
                'had_existing_service' => $hadExistingService,
                'exit_code' => $result->exitCode(),
                'stderr' => trim($result->errorOutput()),
            ],
        );
    }
}
