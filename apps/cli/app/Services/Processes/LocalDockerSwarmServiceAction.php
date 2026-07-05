<?php

declare(strict_types=1);

namespace App\Services\Processes;

use Symfony\Component\Process\Process;

final readonly class LocalDockerSwarmServiceAction
{
    private const array ACTIONS = ['apply', 'remove', 'restart', 'start', 'stop'];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function run(string $action, string $service, array $payload = []): array
    {
        $action = $this->action($action);
        $service = $this->service($service);

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
