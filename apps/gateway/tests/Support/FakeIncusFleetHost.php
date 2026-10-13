<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\TaskVms\TaskVmHost;
use App\Infrastructure\Processes\CommandResult;
use Closure;
use RuntimeException;

/** One fake task VM host. */
final class FakeIncusFleetHost
{
    /** @var array<string, list<string>> fingerprint => aliases */
    public array $images = [];

    /** @var array<string, ?string> name => base image */
    public array $instances = [];

    public ?string $lan = null;

    public string $user = 'nckrtl';

    /** The SSH host key, `ssh-ed25519 <base64>`. */
    public string $hostKey;

    /** A host key that sshd on the address presents instead, as a host in the middle of the LAN would. */
    public ?string $sshdKey = null;

    /** The transfer public key, `ssh-ed25519 <base64>`. */
    public ?string $transferKey = null;

    /** The installed receiver script. */
    public ?string $receiver = null;

    /** @var list<string> the managed user's `authorized_keys` lines */
    public array $authorizedKeys = [];

    /** @var array<string, string> fingerprint => export file */
    public array $exports = [];

    /** @var list<string> each fingerprint the receiver imported */
    public array $received = [];

    /** An operation that fails, such as `transfer`, `launch`, or `ssh-keygen`. */
    public ?string $fail = null;

    /** The fingerprint the receiver imports instead of the source's. */
    public ?string $copyAs = null;

    public int $boots = 1;

    /** The fingerprint a build publishes. */
    public string $publishAs = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private int $cloudInitPolls = 0;

    public function __construct(public string $address = '')
    {
        $this->hostKey = self::publicKey();
    }

    /** A random `ssh-ed25519` public key without a comment. */
    public static function publicKey(): string
    {
        return 'ssh-ed25519 '.base64_encode("\0\0\0\x0bssh-ed25519\0\0\0\x20".random_bytes(32));
    }

    /** @param  list<string>  $operation  the arguments after `incus --project <project>` */
    public function project(array $operation, ?string $input): CommandResult
    {
        return match (true) {
            $operation[0] === 'project' && $operation[1] === 'show' => $this->answer('project show', static fn (): string => "name: orbit-tasks\n"),
            $operation === ['list', '--format', 'csv', '--columns', 'n'] => $this->answer('list', fn (): string => implode('', array_map(static fn (string $name): string => $name."\n", array_keys($this->instances)))),
            $operation[0] === 'list' => $this->answer('list', fn (): string => (string) json_encode(array_map(
                static fn (string $name, ?string $image): array => ['name' => $name, 'config' => $image === null ? [] : ['volatile.base_image' => $image]],
                array_keys($this->instances),
                $this->instances,
            ))),
            $operation[0] === 'image' && $operation[1] === 'list' => $this->answer('image list', fn (): string => (string) json_encode(array_map(
                static fn (string $fingerprint, array $aliases): array => ['fingerprint' => $fingerprint, 'aliases' => array_map(static fn (string $name): array => ['name' => $name], $aliases)],
                array_keys($this->images),
                $this->images,
            ))),
            $operation[0] === 'launch' => $this->answer('launch', function () use ($operation): void {
                $this->instances[end($operation)] = $this->fingerprintOf($operation[count($operation) - 2]);
            }),
            $operation[0] === 'exec' => $this->exec(array_slice($operation, array_search('--', $operation, true) + 2)),
            $operation[0] === 'config' && $operation[2] === 'show' => $this->answer('metadata show', static fn (): string => "templates:\n  /etc/hostname:\n    template: hostname.tpl\n"),
            $operation[0] === 'config' && $operation[2] === 'edit' => $this->answer('metadata edit'),
            $operation[0] === 'stop' => $this->answer('stop'),
            $operation[0] === 'publish' => $this->answer('publish', function () use ($operation): void {
                $this->images[$this->publishAs] = [$operation[4]];
            }),
            $operation[0] === 'delete' => $this->answer('delete', function () use ($operation): void {
                unset($this->instances[end($operation)]);
            }),
            $operation[0] === 'image' && $operation[1] === 'alias' => $this->answer('image alias', function () use ($operation): void {
                if (! isset($this->images[$operation[5]])) {
                    throw new RuntimeException('Error: Image not found');
                }
                $this->images[$operation[5]][] = $operation[4];
            }),
            $operation[0] === 'image' && $operation[1] === 'delete' => $this->answer('image delete', function () use ($operation): void {
                unset($this->images[end($operation)]);
            }),
            // A host without workspace templates or clones.
            $operation[0] === 'storage' && $operation[1] === 'volume' && $operation[2] === 'list' => $this->answer('storage volume list', static fn (): string => '[]'),
            $operation[0] === 'storage' && $operation[1] === 'volume' && $operation[2] === 'delete' => $this->answer('storage volume delete'),
            default => throw new RuntimeException('Unexpected incus command: '.implode(' ', $operation)),
        };
    }

    /** @param  Closure(): mixed|null  $effect */
    public function answer(string $operation, ?Closure $effect = null): CommandResult
    {
        if ($this->fail === $operation) {
            return new CommandResult(1, '', "Error: {$operation} failed\n", 1, false);
        }
        try {
            $output = $effect instanceof Closure ? $effect() : null;
        } catch (RuntimeException $exception) {
            return new CommandResult(1, '', $exception->getMessage()."\n", 1, false);
        }

        return new CommandResult(0, is_string($output) ? $output : '', '', 1, false);
    }

    public function retarget(string $fingerprint): void
    {
        foreach ($this->images as $image => $aliases) {
            $this->images[$image] = array_values(array_diff($aliases, [TaskVmHost::BaseImage]));
        }
        $this->images[$fingerprint][] = TaskVmHost::BaseImage;
    }

    /** @param  list<string>  $command */
    private function exec(array $command): CommandResult
    {
        // `timeout <seconds> <command>`, as the cloud-init status read runs.
        if ($command[0] === 'timeout') {
            $command = array_slice($command, 2);
        }
        // A resource script runs as `bash -ceu <script> -- <arguments>`; it answers by its name.
        if ($command[0] === 'bash' && ($command[1] ?? null) === '-ceu') {
            return $this->answer(TaskVmScriptNames::of($command[2]) ?? 'exec');
        }

        return match ($command[0]) {
            // The guest agent is not up on the first poll.
            'cloud-init' => $this->cloudInitPolls++ % 2 === 0
                ? new CommandResult(1, '', 'Error: VM agent isn\'t currently running', 1, false)
                : new CommandResult(0, '{"status":"done","errors":[]}', '', 1, false),
            'uname' => new CommandResult(0, "x86_64\n", '', 1, false),
            'bash' => ($command[1] ?? null) === '-s' ? new CommandResult(0, "{\"ok\":true}\n", '', 1, false) : $this->answer('exec'),
            'journalctl' => new CommandResult(0, implode('', array_map(static fn (int $boot): string => "{$boot} 0f1e2d3c4b5a69788796a5b4c3d2e1f0 Sat 2026-10-10 03:01:00 UTC\n", range(1 - $this->boots, 0))), '', 1, false),
            'test' => $this->answer('authorized key'),
            'ssh-keygen' => $this->answer('ssh-keygen', static fn (): string => "256 SHA256:tHGyu/bpS80ShKGdwihLDLg9TzFRZCdsWqnuyInfRsg root@smoke (ED25519)\n"),
            default => $this->answer('exec'),
        };
    }

    private function fingerprintOf(string $alias): ?string
    {
        foreach ($this->images as $fingerprint => $aliases) {
            if (in_array($alias, $aliases, true)) {
                return $fingerprint;
            }
        }

        return null;
    }
}
