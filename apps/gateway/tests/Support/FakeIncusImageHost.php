<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmHost;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use Tests\Support\IncusGuestCall;

const IMAGE_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeGatewayPublicKeyMaterial0123456789abcd orbit@gateway';

const IMAGE_NEW = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

const IMAGE_OLD = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

const IMAGE_USED = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

const IMAGE_STOCK = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';

/** The metadata of a builder VM from the stock image, as `incus config metadata show` prints it. */
const IMAGE_METADATA = <<<'YAML'
    architecture: x86_64
    creation_date: 1791653894
    properties:
      os: ubuntu
      release: resolute
    templates:
      /etc/hostname:
        when:
        - create
        - copy
        create_only: false
        template: hostname.tpl
        properties: {}
      /var/lib/cloud/seed/nocloud-net/meta-data:
        when:
        - create
        - copy
        create_only: false
        template: cloud-init-meta-data.tpl
        properties: {}
      /var/lib/cloud/seed/nocloud-net/user-data:
        when:
        - create
        - copy
        create_only: false
        template: cloud-init-user-data.tpl
        properties:
          default: |
            #cloud-config
            {}
    YAML;

/**
 * An Incus host that answers by argv: `$state` holds its images (fingerprint => aliases) and instances
 * (name => base image), and `$fail` names an operation that fails.
 */
final class FakeIncusImageHost implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    /** @var list<IncusGuestCall> what ran in a guest, in order */
    public array $guest = [];

    /** @var array<string, list<string>> */
    public array $images = [];

    /** @var array<string, ?string> */
    public array $instances = [];

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

    private int $cloudInitPolls = 0;

    public bool $bootRunning = false;

    /** A test can interleave another operation after the remote result has been captured. */
    public ?Closure $afterExecute = null;

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
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
        if ($argv[3] === 'query') {
            return $this->answer('query', fn () => $this->retarget(json_decode($argv[7], true)['target']));
        }
        $operation = array_slice($argv, 5);

        return match (true) {
            $operation[0] === 'list' => $this->answer('list', fn () => json_encode(array_map(
                static fn (string $name, ?string $image): array => ['name' => $name, 'config' => $image === null ? [] : ['volatile.base_image' => $image]],
                array_keys($this->instances),
                $this->instances,
            ))),
            $operation[0] === 'image' && $operation[1] === 'list' => $this->answer('image list', fn () => json_encode(array_map(
                static fn (string $fingerprint, array $aliases): array => ['fingerprint' => $fingerprint, 'aliases' => array_map(static fn (string $name): array => ['name' => $name], $aliases)],
                array_keys($this->images),
                $this->images,
            ))),
            $operation[0] === 'launch' => $this->answer('launch', fn () => $this->instances[end($operation)] = $this->fingerprintOf($operation[count($operation) - 2])),
            $operation[0] === 'exec' => $this->exec($this->guest[] = IncusGuestCall::decode($command)),
            $operation[0] === 'config' && $operation[2] === 'show' => $this->answer('metadata show', static fn (): string => IMAGE_METADATA),
            $operation[0] === 'config' && $operation[2] === 'edit' => $this->answer('metadata edit', function () use ($operation, $command): void {
                $this->metadata[end($operation)] = $command->input;
            }),
            $operation[0] === 'stop' => $this->answer('stop'),
            $operation[0] === 'publish' => $this->answer('publish', function () use ($operation): void {
                $fingerprint = $this->recipeImages ? hash('sha256', $operation[4]) : IMAGE_NEW;
                $this->images[$fingerprint] = [$operation[4]];
                foreach (array_slice($operation, 7) as $property) {
                    [$key, $value] = explode('=', $property, 2);
                    $this->imageProperties[$fingerprint][$key] = $value;
                }
            }),
            $operation[0] === 'delete' => $this->answer('delete '.end($operation), function () use ($operation): void {
                unset($this->instances[end($operation)]);
            }),
            $operation[0] === 'image' && $operation[1] === 'alias' && $operation[2] === 'delete' => $this->answer('image alias delete', function () use ($operation): void {
                foreach ($this->images as &$aliases) {
                    $aliases = array_values(array_diff($aliases, [end($operation)]));
                }
                unset($aliases);
            }),
            $operation[0] === 'image' && $operation[1] === 'alias' => $this->answer('image alias', fn () => $this->images[$operation[5]][] = $operation[4]),
            $operation[0] === 'image' && $operation[1] === 'delete' => $this->answer('image delete', function () use ($operation): void {
                unset($this->images[end($operation)]);
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

        if ($command[0] === 'bash' && str_contains($command[2] ?? '', 'cat >> "$1"')) {
            $path = $command[4];
            $this->recipeLogs[$path] = ($this->recipeLogs[$path] ?? '').(string) $call->input;

            return new CommandResult(0, '', '', 1, false);
        }

        return match ($command[0]) {
            'tail' => isset($this->recipeLogs[$command[4]])
                ? new CommandResult(0, implode("\n", array_slice(explode("\n", rtrim($this->recipeLogs[$command[4]])), -40)), '', 1, false)
                : new CommandResult(1, '', 'Log unavailable', 1, false),
            // The guest agent is not up on the first poll, cloud-init runs on the second.
            'cloud-init' => match (true) {
                $this->bootRunning => new CommandResult(0, '{"status":"running","errors":[]}', '', 1, false),
                $this->fail === 'cloud-init' => new CommandResult(1, '{"status":"error","errors":["apt failed"]}', '', 1, false),
                $this->cloudInitPolls++ % 3 === 0 => new CommandResult(1, '', 'Error: VM agent isn\'t currently running', 1, false),
                $this->cloudInitPolls % 3 === 2 => new CommandResult(0, '{"status":"running","errors":[]}', '', 1, false),
                default => new CommandResult(0, '{"status":"done","errors":[]}', '', 1, false),
            },
            'dpkg' => new CommandResult(0, "amd64\n", '', 1, false),
            'python3' => $this->answer('recipe '.json_decode($command[3], true)['profile'].':'.$command[2], static fn (): string => '{"ok":true}'),
            'uname' => new CommandResult(0, "x86_64\n", '', 1, false),
            'journalctl' => new CommandResult(0, implode('', array_map(static fn (int $boot): string => "{$boot} 0f1e2d3c4b5a69788796a5b4c3d2e1f0 Sat 2026-10-10 03:01:00 UTC\n", range(1 - $this->boots, 0))), '', 1, false),
            'test' => $this->answer('authorized key'),
            'sh' => $this->answer('orbit user'),
            'ssh-keygen' => $this->answer('ssh-keygen', static fn (): string => "256 SHA256:tHGyu/bpS80ShKGdwihLDLg9TzFRZCdsWqnuyInfRsg root@smoke (ED25519)\n"),
            'bash' => $command[1] === '-s'
                ? ($this->fail === 'clean' ? new CommandResult(1, '', "base-image-clean: an SSH host key remains\n", 1, false) : new CommandResult(0, "{\"ok\":true}\n", '', 1, false))
                : $this->answer(match ($call->script) {
                    'image-check.sh' => 'lean boot',
                    default => 'exec',
                }),
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
