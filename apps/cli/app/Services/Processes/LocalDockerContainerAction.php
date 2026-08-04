<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Services\Docker\LocalDockerCommandContext;
use Symfony\Component\Process\Process;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
final readonly class LocalDockerContainerAction
{
    private const array ACTIONS = [
        'apply',
        'ensure-network',
        'is-active',
        'probe',
        'remove',
        'restart',
        'start',
        'stop',
    ];

    public function __construct(
        private LocalDockerCommandContext $docker,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function run(array $payload): array
    {
        $action = $this->action($payload['action'] ?? null);

        if (in_array($action, ['apply', 'ensure-network', 'probe'], strict: true)) {
            $spec = LocalDockerProcessContainerSpec::from($payload['spec'] ?? null);

            if ($action === 'ensure-network') {
                return $this->ensureNetworkResult($spec);
            }

            if ($action === 'probe') {
                return $this->probe($spec);
            }

            if ($this->preparePrerequisites($payload['prepare_prerequisites'] ?? false)) {
                return $this->applyWithPrerequisites($spec);
            }

            return $this->apply($spec);
        }

        $container = $this->container($payload['container'] ?? null);

        if ($action === 'remove') {
            return $this->remove($container);
        }

        return $this->lifecycle($action, $container);
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function applyWithPrerequisites(LocalDockerProcessContainerSpec $spec): array
    {
        $this->preparePrerequisitesFor($spec);

        return $this->apply($spec);
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function apply(LocalDockerProcessContainerSpec $spec): array
    {
        $this->ensureNetwork($spec);

        $inspection = $this->inspect($spec);
        $hadExistingContainer = $inspection !== null;
        $observedHash = $this->observedSpecHash($inspection);

        if ($hadExistingContainer && hash_equals($spec->expectedHash, $observedHash ?? '')) {
            return $this->applyResult(
                spec: $spec,
                outcome: 'unchanged',
                hadExistingContainer: true,
                changed: false,
                summary: "Docker process container {$spec->name} already matches gateway intent.",
            );
        }

        if ($hadExistingContainer) {
            $remove = $this->runProcess(['docker', 'rm', '-f', $spec->name]);

            if (! $remove->isSuccessful()) {
                throw $this->applyFailure('remove drifted', $spec, $remove, true);
            }
        }

        $create = $this->runProcess($spec->createCommand());

        if (! $create->isSuccessful()) {
            throw $this->applyFailure('create', $spec, $create, $hadExistingContainer);
        }

        return $this->applyResult(
            spec: $spec,
            outcome: $hadExistingContainer ? 'recreated' : 'created',
            hadExistingContainer: $hadExistingContainer,
            changed: true,
            summary: "Applied Docker process container {$spec->name}.",
        );
    }

    private function preparePrerequisitesFor(LocalDockerProcessContainerSpec $spec): void
    {
        $pull = $this->runProcess(['docker', 'pull', $spec->image]);

        if (! $pull->isSuccessful()) {
            throw $this->applyFailure('pull image', $spec, $pull, false);
        }

        $sources = array_values(array_filter(
            $spec->bindMountSources(),
            fn (string $source): bool => ! $this->privilegedPathExists($source),
        ));

        if ($sources === []) {
            return;
        }

        $mkdir = $this->runProcess(['sudo', 'mkdir', '-p', ...$sources]);

        if (! $mkdir->isSuccessful()) {
            throw $this->applyFailure('prepare mount sources', $spec, $mkdir, false);
        }
    }

    private function privilegedPathExists(string $path): bool
    {
        if ($this->runProcess(['sudo', 'test', '-e', $path])->isSuccessful()) {
            return true;
        }

        return $this->runProcess(['sudo', 'test', '-L', $path])->isSuccessful();
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function ensureNetworkResult(LocalDockerProcessContainerSpec $spec): array
    {
        $changed = $this->ensureNetwork($spec);

        return [
            'data' => [
                'action' => 'ensure-network',
                'network' => $spec->network,
                'changed' => $changed,
            ],
            'meta' => [],
        ];
    }

    private function ensureNetwork(LocalDockerProcessContainerSpec $spec): bool
    {
        $inspect = $this->runProcess(['docker', 'network', 'inspect', $spec->network]);

        if ($inspect->isSuccessful()) {
            return false;
        }

        $create = $this->runProcess([
            'docker',
            'network',
            'create',
            '--label',
            'orbit.managed=true',
            '--label',
            'orbit.network.kind=runtime',
            $spec->network,
        ]);

        if ($create->isSuccessful()) {
            return true;
        }

        if ($this->docker->networkAlreadyExists($create->getErrorOutput().' '.$create->getOutput(), $spec->network)) {
            return false;
        }

        throw $this->applyFailure('create network', $spec, $create, false);
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function probe(LocalDockerProcessContainerSpec $spec): array
    {
        $inspection = $this->inspect($spec);

        return [
            'data' => [
                'action' => 'probe',
                'container' => $spec->name,
                'exists' => $inspection !== null,
                'spec_hash' => $this->observedSpecHash($inspection),
                'inspection' => $inspection,
            ],
            'meta' => [],
        ];
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function inspect(LocalDockerProcessContainerSpec $spec): ?array
    {
        $inspect = $this->runProcess(['docker', 'container', 'inspect', '--format', '{{json .}}', $spec->name]);

        if (! $inspect->isSuccessful()) {
            if ($this->isDockerNoSuchContainer($inspect)) {
                return null;
            }

            throw $this->applyFailure('inspect', $spec, $inspect, false);
        }

        $output = trim($inspect->getOutput());

        if ($output === '') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        if (is_array($decoded)) {
            return $decoded;
        }

        throw new LocalDockerContainerFailure(
            errorCode: 'docker_container.inspect_failed',
            message: "Docker returned an invalid inspect payload for '{$spec->name}'.",
            meta: [
                'action' => 'apply',
                'container' => $spec->name,
                'had_existing_container' => false,
            ],
        );
    }

    /**
     * @param  array<array-key, mixed>|null  $inspection
     */
    private function observedSpecHash(?array $inspection): ?string
    {
        if (
            ! isset($inspection['Config'])
            || ! is_array($inspection['Config'])
            || ! isset($inspection['Config']['Labels'])
            || ! is_array($inspection['Config']['Labels'])
        ) {
            return null;
        }

        return is_string($inspection['Config']['Labels'][LocalDockerProcessContainerSpec::SPEC_HASH_LABEL] ?? null)
            ? $inspection['Config']['Labels'][LocalDockerProcessContainerSpec::SPEC_HASH_LABEL]
            : null;
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function applyResult(
        LocalDockerProcessContainerSpec $spec,
        string $outcome,
        bool $hadExistingContainer,
        bool $changed,
        string $summary,
    ): array {
        return [
            'data' => [
                'action' => 'apply',
                'container' => $spec->name,
                'outcome' => $outcome,
                'had_existing_container' => $hadExistingContainer,
                'changed' => $changed,
                'summary' => $summary,
            ],
            'meta' => [],
        ];
    }

    private function applyFailure(
        string $step,
        LocalDockerProcessContainerSpec $spec,
        Process $result,
        bool $hadExistingContainer,
    ): LocalDockerContainerFailure {
        $output = trim($result->getErrorOutput().' '.$result->getOutput());
        $message = $output !== '' ? $output : 'unknown error';

        return new LocalDockerContainerFailure(
            errorCode: 'docker_container.apply_failed',
            message: "Failed to {$step} {$spec->name} container: {$message}",
            meta: [
                'action' => 'apply',
                'container' => $spec->name,
                'had_existing_container' => $hadExistingContainer,
                'exit_code' => $result->getExitCode(),
                'stderr' => trim($result->getErrorOutput()),
            ],
        );
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function remove(string $container): array
    {
        $inspect = $this->runProcess(['docker', 'container', 'inspect', $container]);

        if (! $inspect->isSuccessful()) {
            if ($this->isDockerNoSuchContainer($inspect)) {
                return [
                    'data' => [
                        'action' => 'remove',
                        'container' => $container,
                        'changed' => false,
                    ],
                    'meta' => [],
                ];
            }

            throw $this->failure('inspect', $container, $inspect);
        }

        $remove = $this->runProcess(['docker', 'rm', '-f', $container]);

        if ($remove->isSuccessful()) {
            return [
                'data' => [
                    'action' => 'remove',
                    'container' => $container,
                    'changed' => true,
                ],
                'meta' => [],
            ];
        }

        throw $this->failure('remove', $container, $remove);
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function lifecycle(string $action, string $container): array
    {
        if ($action === 'is-active') {
            $result = $this->runProcess([
                'docker',
                'inspect',
                '--format',
                '{{.State.Running}}',
                $container,
            ]);
            $running = $result->isSuccessful() && trim($result->getOutput()) === 'true';

            if ($running) {
                return [
                    'data' => [
                        'action' => $action,
                        'container' => $container,
                        'changed' => false,
                    ],
                    'meta' => [],
                ];
            }

            throw $this->failure($action, $container, $result);
        }

        $result = $this->runProcess(['docker', $action, $container]);

        if ($result->isSuccessful()) {
            return [
                'data' => [
                    'action' => $action,
                    'container' => $container,
                    'changed' => true,
                ],
                'meta' => [],
            ];
        }

        throw $this->failure($action, $container, $result);
    }

    private function action(mixed $value): string
    {
        if (is_string($value) && in_array($value, self::ACTIONS, strict: true)) {
            return $value;
        }

        throw new LocalDockerContainerFailure(
            errorCode: 'validation_failed',
            message: 'Docker container action is invalid.',
            meta: ['field' => 'action'],
        );
    }

    private function preparePrerequisites(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        throw new LocalDockerContainerFailure(
            errorCode: 'validation_failed',
            message: 'Docker container prepare_prerequisites flag is invalid.',
            meta: ['field' => 'prepare_prerequisites'],
        );
    }

    private function container(mixed $value): string
    {
        if (is_string($value) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $value) === 1) {
            return $value;
        }

        throw new LocalDockerContainerFailure(
            errorCode: 'validation_failed',
            message: 'Docker container name is invalid.',
            meta: ['field' => 'container'],
        );
    }

    /**
     * @param  list<string>  $command
     */
    private function runProcess(array $command): Process
    {
        $process = new Process($command, null, $this->docker->environmentFor($command));
        $process->setTimeout(120);
        $process->run();

        return $process;
    }

    private function failure(string $action, string $container, Process $result): LocalDockerContainerFailure
    {
        return new LocalDockerContainerFailure(
            errorCode: "docker_container.{$action}_failed",
            message: "Docker container {$action} failed for '{$container}'.",
            meta: [
                'action' => $action,
                'container' => $container,
                'exit_code' => $result->getExitCode(),
                'stderr' => trim($result->getErrorOutput()),
            ],
        );
    }

    private function isDockerNoSuchContainer(Process $result): bool
    {
        return preg_match('/No such (object|container)/i', $result->getErrorOutput().' '.$result->getOutput()) === 1;
    }
}
