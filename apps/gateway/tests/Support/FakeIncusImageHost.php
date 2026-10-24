<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmHost;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentGuest;
use App\Infrastructure\AgentCommands\GuestAgentCommandRunner;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeGuestAgentCommandRunner;
use Tests\Support\IncusGuestCall;
use Tests\Support\RecipeImageAnswers;

const IMAGE_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeGatewayPublicKeyMaterial0123456789abcd orbit@gateway';

const IMAGE_NEW = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

const IMAGE_OLD = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

const IMAGE_USED = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

const IMAGE_STOCK = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';

/**
 * An Incus host that answers by argv: `$state` holds its images (fingerprint => aliases) and instances
 * (name => base image), and `$fail` names an operation that fails.
 */
final class FakeIncusImageHost implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    /** @var list<string> */
    public array $events = [];

    /** @var list<SshConnection> */
    public array $connections = [];

    /** @var list<IncusGuestCall> what ran in a guest, in order */
    public array $guest = [];

    /** @var array<string, list<string>> */
    public array $images = [];

    /** @var array<string, ?string> */
    public array $instances = [];

    /** @var list<string> the custom volumes of the host's pool */
    public array $volumes = [];

    /** @var list<array<string, mixed>> the input of each `incus.build-topology-seed` run */
    public array $seeds = [];

    public ?string $fail = null;

    public bool $recipeImages = false;

    public ?CommandResult $recipeFailure = null;

    /** @var array<string, string> */
    private array $recipeLogs = [];

    /** @var array<string, array<string, string>> */
    public array $imageProperties = [];

    /** The image metadata that `config metadata edit` wrote, by instance. */
    public array $metadata = [];

    /** How many boots the smoke VM's journal lists. */
    public int $boots = 1;

    public bool $bootRunning = false;

    /** A test can interleave another operation after the remote result has been captured. */
    public ?Closure $afterExecute = null;

    public ?Closure $afterImage = null;

    public array $imageCalls = [];

    public function bindAgentInventory(): void
    {
        $guests = new FakeGuestAgentCommandRunner;
        $guests->answer = function (AgentGuest $guest, string $command, array $input): array {
            $this->events[] = $command;
            if ($command === 'image.recipe-log') {
                $path = '/var/log/orbit/recipe-images/'.$input['profile'].'-'.$input['phase'].'.log';
                $this->recipeLogs[$path] = ($this->recipeLogs[$path] ?? '').$input['text'];
            }
            if ($command === 'guest.cloud-init') {
                if ($this->fail === 'cloud-init') {
                    throw new AgentCommandRefused($command, 'task_vm.bootstrap_failed', 'Cloud-init failed.', ['errors' => '["apt failed"]']);
                }

                return ['ready' => ! $this->bootRunning];
            }
            if ($command === 'image.check' && ($this->boots !== 1 || in_array($this->fail, ['ssh-keygen', 'authorized key'], true))) {
                throw new AgentCommandRefused($command, 'task_vm.image_check_failed', 'The smoke image is invalid.');
            }

            if ($command === 'image.clean' && $this->fail === 'clean') {
                throw new AgentCommandRefused('image.clean', 'task_vm.image_clean_failed', 'An SSH host key remains.');
            }

            $phase = $command === 'image.verify' ? 'verify' : ($input['key']['phase'] ?? null);
            if (in_array($command, ['image.start-phase', 'image.restart-phase', 'image.verify'], true) && $this->fail === 'recipe base:'.$phase) {
                $failure = $this->recipeFailure ?? new CommandResult(1, '', 'Error: recipe base:'.$phase.' failed', 1, false);
                if ($command === 'image.verify') {
                    throw new AgentCommandRefused($command, 'task_vm.image_build_failed', 'Verification failed.', ['reason' => $failure->stderr]);
                }

                return RecipeImageAnswers::observation('failed', reason: 'failed with exit '.$failure->exitCode, exit: $failure->exitCode, log: $failure->stdout.$failure->stderr);
            }

            return RecipeImageAnswers::success($command, $input);
        };
        app()->instance(GuestAgentCommandRunner::class, $guests);
        $agents = new FakeAgentCommandRunner;
        $agents->answer('incus.images', function (): array {
            if ($this->fail === 'image list') {
                throw new AgentCommandRefused('incus.images', 'task_vm.host_command_failed', 'Image list failed.', ['operation' => 'image list', 'exit_code' => 1]);
            }

            return ['images' => $this->images];
        });
        $agents->answer('incus.instance-images', fn (): array => ['instances' => $this->instances]);
        $agents->answer('incus.image', function (array $input): array {
            $op = $input['operation'];
            $this->imageCalls[] = $op;
            $this->events[] = 'incus.image.'.$op['kind'];
            $operation = match ($op['kind']) {
                'prepare_metadata' => 'metadata edit', 'publish' => 'publish', 'promote' => 'image alias', 'prune' => 'image delete',
            };
            if ($this->fail === $operation) {
                throw new AgentCommandRefused('incus.image', 'task_vm.host_command_failed', 'Image operation failed.', ['operation' => $operation, 'exit_code' => 1, 'stderr' => "Error: {$operation} failed"]);
            }
            $fingerprint = null;
            if ($op['kind'] === 'publish') {
                $fingerprint = $this->recipeImages ? hash('sha256', $op['alias']) : IMAGE_NEW;
                $this->images[$fingerprint] = [$op['alias']];
                $this->imageProperties[$fingerprint] = (array) $op['properties'];
            } elseif ($op['kind'] === 'promote') {
                $fingerprint = $op['fingerprint'];
                $this->retarget($fingerprint);
            } elseif ($op['kind'] === 'prune') {
                foreach ($this->images as $pin => $aliases) {
                    if (in_array($pin, $this->instances, true)) {
                        continue;
                    }
                    if ($aliases === [] || array_diff($aliases, $op['obsolete_aliases']) !== [] || in_array($pin, $op['protected_fingerprints'], true)) {
                        $this->images[$pin] = array_values(array_diff($aliases, $op['retired_aliases']));

                        continue;
                    }
                    unset($this->images[$pin]);
                    $seed = 'orbit-topology-seed-'.substr($pin, 0, 12);
                    $this->volumes = array_values(array_diff($this->volumes, [$seed, $seed.'-new']));
                }
            }
            ($this->afterImage)?->__invoke($op);

            return ['ready' => true, 'fingerprint' => $fingerprint];
        });
        $agents->answer('incus.build-topology-seed', function (array $input): array {
            $this->events[] = 'incus.build-topology-seed';
            $volume = 'orbit-topology-seed-'.substr($input['fingerprint'], 0, 12).'-new';
            $this->volumes = [...array_values(array_diff($this->volumes, [$volume])), $volume];
            if ($this->fail === 'topology seed') {
                throw new AgentCommandRefused('incus.build-topology-seed', 'task_vm.topology_seed_failed', 'Seed failed.', ['reason' => 'Error: topology seed failed']);
            }
            $this->seeds[] = $input;

            return ['ready' => true, 'seconds' => []];
        });
        $agents->answer('incus.instances', fn (): array => ['names' => array_keys($this->instances)]);
        $agents->answer('incus.volumes', fn (): array => ['names' => $this->volumes]);
        $agents->answer('incus.volume', function (array $input): array {
            $operation = $input['operation'];
            $this->events[] = 'incus.volume.'.$operation['kind'];
            $name = $operation['kind'] === 'finish_seed' ? 'orbit-topology-seed-'.substr($operation['fingerprint'], 0, 12) : $operation['name'];
            $failure = $operation['kind'] === 'finish_seed' ? 'volume rename' : 'volume delete '.$name;
            if ($this->fail === $failure) {
                throw new AgentCommandRefused('incus.volume', 'task_vm.host_command_failed', 'Volume change failed.', ['operation' => $failure, 'exit_code' => 1]);
            }
            if ($operation['kind'] === 'finish_seed') {
                $this->volumes = [...array_values(array_diff($this->volumes, [$name.'-new', $name])), $name];
            } else {
                $this->volumes = array_values(array_diff($this->volumes, [$name]));
            }

            return ['name' => $name];
        });
        app()->instance(AgentCommandRunner::class, $agents);
    }

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->connections[] = $connection;
        $result = $this->executeCommand($command);
        if ($this->afterExecute !== null) {
            ($this->afterExecute)($command);
        }

        return $result;
    }

    private function executeCommand(RemoteCommand $command): CommandResult
    {
        $this->commands[] = $command;
        $argv = $command->arguments;
        $operation = array_slice($argv, 5);
        $this->events[] = implode(' ', array_slice($operation, 0, 4));

        return match (true) {
            $operation[0] === 'list' => $this->answer('list', fn () => json_encode(array_map(
                static fn (string $name, ?string $image): array => ['name' => $name, 'config' => $image === null ? [] : ['volatile.base_image' => $image]],
                array_keys($this->instances),
                $this->instances,
            ))),
            $operation[0] === 'launch' => $this->answer('launch', fn () => $this->instances[end($operation)] = $this->fingerprintOf($operation[count($operation) - 2])),
            $operation[0] === 'exec' => $this->exec($this->guest[] = IncusGuestCall::decode($command)),
            $operation[0] === 'delete' => $this->answer('delete '.end($operation), function () use ($operation): void {
                unset($this->instances[end($operation)]);
            }),
            default => throw new RuntimeException('Unexpected incus command: '.implode(' ', $argv)),
        };
    }

    private function exec(IncusGuestCall $call): CommandResult
    {
        if ($call->user !== 'root') {
            return $this->answer('program');
        }
        // `timeout <seconds> <command>`, as the cloud-init status read runs.
        $command = $call->argv[0] === 'timeout' ? array_slice($call->argv, 2) : $call->argv;

        return match ($command[0]) {
            'tail' => isset($this->recipeLogs[$command[4]])
                ? new CommandResult(0, implode("\n", array_slice(explode("\n", rtrim($this->recipeLogs[$command[4]])), -40)), '', 1, false)
                : new CommandResult(1, '', 'Log unavailable', 1, false),
            'dpkg' => new CommandResult(0, "amd64\n", '', 1, false),
            'uname' => new CommandResult(0, "x86_64\n", '', 1, false),
            'journalctl' => new CommandResult(0, implode('', array_map(static fn (int $boot): string => "{$boot} 0f1e2d3c4b5a69788796a5b4c3d2e1f0 Sat 2026-10-10 03:01:00 UTC\n", range(1 - $this->boots, 0))), '', 1, false),
            'test' => $this->answer('authorized key'),
            'sh' => $this->answer('orbit user'),
            'ssh-keygen' => $this->answer('ssh-keygen', static fn (): string => "256 SHA256:tHGyu/bpS80ShKGdwihLDLg9TzFRZCdsWqnuyInfRsg root@smoke (ED25519)\n"),
            default => $this->answer('exec'),
        };
    }

    private function fingerprintOf(string $alias): ?string
    {
        if (array_key_exists($alias, $this->images)) {
            return $alias;
        }
        foreach ($this->images as $fingerprint => $aliases) {
            if (in_array($alias, $aliases, true)) {
                return $fingerprint;
            }
        }

        return null;
    }

    private function retarget(string $fingerprint): void
    {
        foreach ($this->images as $image => $aliases) {
            $this->images[$image] = array_values(array_diff($aliases, [TaskVmHost::BaseImage]));
        }
        $this->images[$fingerprint][] = TaskVmHost::BaseImage;
    }

    private function answer(string $operation, ?Closure $effect = null): CommandResult
    {
        if ($this->fail === $operation) {
            return str_starts_with($operation, 'recipe ') && $this->recipeFailure !== null
                ? $this->recipeFailure : new CommandResult(1, '', "Error: {$operation} failed\n", 1, false);
        }
        $output = $effect instanceof Closure ? $effect() : null;

        return new CommandResult(0, is_string($output) ? $output : '', '', 1, false);
    }
}
