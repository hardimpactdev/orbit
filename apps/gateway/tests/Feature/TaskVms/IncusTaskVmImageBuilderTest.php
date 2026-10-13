<?php

declare(strict_types=1);

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Infrastructure\Nodes\NodeAgentFootprint;
use App\Infrastructure\Nodes\NodeBootstrapCommandFactory;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Infrastructure\Nodes\Roles\NodeRolePrerequisiteCommandFactory;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\IncusTaskVmImageBuilder;
use App\Infrastructure\TaskVms\TaskVmBakedPrograms;
use App\Infrastructure\TaskVms\TaskVmBaseImage;
use App\Infrastructure\TaskVms\TaskVmImageRecipe;
use App\Infrastructure\TaskVms\TaskVmRuntime;
use App\Infrastructure\Tools\ComposerToolManager;
use App\Infrastructure\Tools\VpToolManager;
use App\Jobs\TaskVms\RefreshTaskVmBaseImages;
use App\Models\Node;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\TaskVmSettingsBuilder;

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

    /** @var array<string, list<string>> */
    public array $images = [];

    /** @var array<string, ?string> */
    public array $instances = [];

    public ?string $fail = null;

    /** The image metadata that `config metadata edit` wrote, by instance. */
    public array $metadata = [];

    /** How many boots the smoke VM's journal lists. */
    public int $boots = 1;

    private int $cloudInitPolls = 0;

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
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
            $operation[0] === 'exec' => $this->exec($operation[array_search('--', $operation, true) + 1], array_slice($operation, array_search('--', $operation, true) + 2)),
            $operation[0] === 'config' && $operation[2] === 'show' => $this->answer('metadata show', static fn (): string => IMAGE_METADATA),
            $operation[0] === 'config' && $operation[2] === 'edit' => $this->answer('metadata edit', function () use ($operation, $command): void {
                $this->metadata[end($operation)] = $command->input;
            }),
            $operation[0] === 'stop' => $this->answer('stop'),
            $operation[0] === 'publish' => $this->answer('publish', fn () => $this->images[IMAGE_NEW] = [$operation[4]]),
            $operation[0] === 'delete' => $this->answer('delete '.end($operation), function () use ($operation): void {
                unset($this->instances[end($operation)]);
            }),
            $operation[0] === 'image' && $operation[1] === 'alias' => $this->answer('image alias', fn () => $this->images[$operation[5]][] = $operation[4]),
            $operation[0] === 'image' && $operation[1] === 'delete' => $this->answer('image delete', function () use ($operation): void {
                unset($this->images[end($operation)]);
            }),
            default => throw new RuntimeException('Unexpected incus command: '.implode(' ', $argv)),
        };
    }

    /** @param  list<string>  $command */
    private function exec(string $name, array $command): CommandResult
    {
        return match ($command[0]) {
            // The guest agent is not up on the first poll, cloud-init runs on the second.
            'cloud-init' => match (true) {
                $this->fail === 'cloud-init' => new CommandResult(1, '{"status":"error","errors":["apt failed"]}', '', 1, false),
                $this->cloudInitPolls++ % 3 === 0 => new CommandResult(1, '', 'Error: VM agent isn\'t currently running', 1, false),
                $this->cloudInitPolls % 3 === 2 => new CommandResult(0, '{"status":"running","errors":[]}', '', 1, false),
                default => new CommandResult(0, '{"status":"done","errors":[]}', '', 1, false),
            },
            'uname' => new CommandResult(0, "x86_64\n", '', 1, false),
            'journalctl' => new CommandResult(0, implode('', array_map(static fn (int $boot): string => "{$boot} 0f1e2d3c4b5a69788796a5b4c3d2e1f0 Sat 2026-10-10 03:01:00 UTC\n", range(1 - $this->boots, 0))), '', 1, false),
            'test' => $this->answer('authorized key'),
            'sh' => $this->answer(str_contains($command[2], 'systemctl') ? 'lean boot' : 'orbit user'),
            'ssh-keygen' => $this->answer('ssh-keygen', static fn (): string => "256 SHA256:tHGyu/bpS80ShKGdwihLDLg9TzFRZCdsWqnuyInfRsg root@smoke (ED25519)\n"),
            'bash' => $command[1] === '-s'
                ? ($this->fail === 'clean' ? new CommandResult(1, '', "base-image-clean: an SSH host key remains\n", 1, false) : new CommandResult(0, "{\"ok\":true}\n", '', 1, false))
                : $this->answer('agent'),
            default => $this->answer($command[0] === 'runuser' ? 'program' : 'exec'),
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
            return new CommandResult(1, '', "Error: {$operation} failed\n", 1, false);
        }
        $output = $effect instanceof Closure ? $effect() : null;

        return new CommandResult(0, is_string($output) ? $output : '', '', 1, false);
    }
}

beforeEach(function (): void {
    Sleep::fake();
    $this->host = Node::query()->create([
        'name' => 'beast', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.7', 'wireguard_ip' => '10.44.0.7', 'user' => 'nckrtl',
    ]);
    $this->settings = TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($this->host->id)]]);
    $this->incus = new FakeIncusImageHost;
    $this->incus->images = [IMAGE_STOCK => [TaskVmHost::SourceImage]];
    app()->instance(SshExecutor::class, $this->incus);
    app()->instance(SshKeyProvider::class, new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/keys/id_ed25519';
        }

        public function publicKey(): string
        {
            return IMAGE_KEY;
        }
    });
    app()->instance(KnownHostsStore::class, new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/keys/known_hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    });
    $this->build = function (array &$stages = []): string {
        return app(IncusTaskVmImageBuilder::class)->build($this->settings->host($this->host->id), $this->host, function (string $stage) use (&$stages): void {
            $stages[] = $stage;
        });
    };
    $this->argv = fn (): array => array_map(static fn (RemoteCommand $command): string => implode(' ', array_slice($command->arguments, 5)), $this->incus->commands);
});

describe('a build', function (): void {
    it('boots the stock image, runs the enrollment programs, cleans, publishes, smoke-tests and promotes the image', function (): void {
        $this->travelTo('2026-10-10 03:00:00');
        $stages = [];

        $fingerprint = ($this->build)($stages);

        $node = new Node(['name' => 'task-vm-base-image', 'platform' => 'linux', 'user' => 'orbit']);
        $programs = [
            app(NodeBootstrapCommandFactory::class)->makeWithPasswordlessSudo($node, 'orbit'),
            app(NodeRolePrerequisiteCommandFactory::class)->caddyPackage($node, RoleName::AppDev),
            app(NodeRolePrerequisiteCommandFactory::class)->make($node, RoleName::AppDev, new ManagedUserAccount('orbit', 'orbit', '/home/orbit')),
            app(VpToolManager::class)->materializeCommand($node),
            app(ComposerToolManager::class)->materializeCommand($node),
            ...array_values(app(RemotePhpPackageManager::class)->appDevPrograms(['8.5', '8.4'])),
            new RemoteCommand(['bash', '-ceu', TaskVmImageRecipe::InstallRust]),
        ];
        $execs = array_values(array_filter($this->incus->commands, static fn (RemoteCommand $command): bool => array_slice($command->arguments, 5, 11) === [
            'exec', '--cwd', '/home/orbit', '--env', 'HOME=/home/orbit', '--env', 'USER=orbit', '--', 'tvm-image-20261010030000', 'runuser', '-u',
        ]));
        $launches = array_values(array_filter($this->incus->commands, static fn (RemoteCommand $command): bool => $command->arguments[5] === 'launch'));

        expect($fingerprint)->toBe(IMAGE_NEW)
            ->and($stages)->toBe(['render', 'sweep', 'launch', 'cloud-init', 'orbit-user', 'bootstrap', 'caddy-package', 'app-dev-prerequisites', 'vp-manager', 'composer-manager', 'app-dev-php-source', 'app-dev-php-8-5', 'app-dev-php-8-4', 'rust-toolchain', 'agent-binary', 'single-boot', 'lean-boot', 'manifest', 'clean', 'publish', 'delete-builder', 'smoke', 'promote', 'prune'])
            ->and(array_map(static fn (RemoteCommand $command): array => [array_slice($command->arguments, 18), $command->input], $execs))
            ->toBe(array_map(static fn (?RemoteCommand $program): array => [$program?->arguments, $program?->input], $programs))
            ->and(array_slice($launches[0]->arguments, -3))->toBe(['--', 'ubuntu-26.04-vm', 'tvm-image-20261010030000'])
            ->and($launches[0]->input)->toContain('package_upgrade')->toContain('libnss3')->toContain('openssh-server')->toContain('bind9-dnsutils')
            ->and(array_slice($launches[1]->arguments, -3))->toBe(['--', 'orbit-task-base-20261010030000', 'tvm-image-20261010030000-smoke'])
            ->and($launches[1]->input)->not->toContain('packages')
            ->and(($this->argv)())->toContain(
                'stop -- tvm-image-20261010030000',
                'publish --compression none --alias orbit-task-base-20261010030000 -- tvm-image-20261010030000',
                'delete --force -- tvm-image-20261010030000',
                'delete --force -- tvm-image-20261010030000-smoke',
                'image alias create -- orbit-task-base '.IMAGE_NEW,
            )
            ->and($this->incus->images)->toBe([IMAGE_STOCK => ['ubuntu-26.04-vm'], IMAGE_NEW => ['orbit-task-base-20261010030000', 'orbit-task-base']])
            ->and($this->incus->instances)->toBe([]);

        $agent = collect($this->incus->commands)->first(static fn (RemoteCommand $command): bool => ($command->arguments[8] ?? null) === 'bash' && $command->arguments[9] === '-ceu');
        $clean = collect($this->incus->commands)->first(static fn (RemoteCommand $command): bool => array_slice($command->arguments, 8) === ['bash', '-s']);
        expect(array_slice($agent->arguments, -2))->toBe([NodeAgentFootprint::downloadUrl('x86_64'), NodeAgentFootprint::checksum('x86_64')])
            ->and($clean->input)->toBe(file_get_contents(resource_path('task-vms/base-image-clean.sh')));
    });

    it('installs the pinned Pi executable for orbit when the Gateway pins one', function (): void {
        $artifact = tempnam(sys_get_temp_dir(), 'pi-server-');
        file_put_contents($artifact, 'pi-server build');
        $digest = hash_file('sha256', $artifact);
        TaskVmSettingsBuilder::bind(['hosts' => $this->settings->hosts, 'piArtifactPath' => $artifact, 'piArtifactSha256' => $digest, 'piModels' => ['gpt-5.6-luna']]);
        $stages = [];

        try {
            ($this->build)($stages);
        } finally {
            unlink($artifact);
        }

        $pi = collect($this->incus->commands)->first(static fn (RemoteCommand $command): bool => $command->protectedInput !== null);
        expect($stages)->toContain('pi-artifact')
            ->and(array_search('pi-artifact', $stages, true))->toBe(array_search('agent-binary', $stages, true) + 1)
            ->and(array_slice($pi->arguments, 5, 16))->toBe([
                'exec', '--cwd', '/home/orbit', '--env', 'HOME=/home/orbit', '--env', 'USER=orbit', '--', 'tvm-image-'.now()->format('YmdHis'),
                'runuser', '-u', 'orbit', '--', 'bash', '-ceu', TaskVmRuntime::InstallPi,
            ])
            ->and(array_slice($pi->arguments, -2))->toBe(['--', $digest]);
    });

    it('drops the seed templates, so a VM from the image boots once and cloud-init reads its user-data from the Incus agent', function (): void {
        $this->travelTo('2026-10-10 03:00:00');

        ($this->build)();

        $metadata = Yaml::parse((string) $this->incus->metadata['tvm-image-20261010030000']);
        $dropIn = collect($this->incus->commands)->first(static fn (RemoteCommand $command): bool => in_array('install', $command->arguments, true));
        expect(array_keys($metadata['templates']))->toBe(['/etc/hostname'])
            ->and($metadata['properties'])->toBe(['os' => 'ubuntu', 'release' => 'resolute'])
            ->and($metadata['creation_date'])->toBe(1791653894)
            ->and(Yaml::parse((string) $this->incus->metadata['tvm-image-20261010030000'], Yaml::PARSE_OBJECT_FOR_MAP)->templates->{'/etc/hostname'}->properties)->toBeInstanceOf(stdClass::class)
            ->and(array_slice($dropIn->arguments, -2))->toBe(['/dev/stdin', '/etc/systemd/system/cloud-init-main.service.d/orbit-incus-agent.conf'])
            ->and($dropIn->input)->toBe("[Unit]\nWants=incus-agent.service\nAfter=incus-agent.service\n");
    });

    it('records every program it ran in the image manifest and keeps work off the boot', function (): void {
        $this->travelTo('2026-10-10 03:00:00');

        ($this->build)();

        $node = new Node(['name' => 'task-vm-base-image', 'platform' => 'linux', 'user' => 'orbit']);
        $manifest = collect($this->incus->commands)->first(static fn (RemoteCommand $command): bool => Arr::last($command->arguments) === TaskVmBakedPrograms::ManifestPath);
        $lean = collect($this->incus->commands)->first(static fn (RemoteCommand $command): bool => ($command->arguments[9] ?? null) === '-ceu' && str_contains((string) Arr::last($command->arguments), 'systemctl mask'));
        $check = collect($this->incus->commands)->first(static fn (RemoteCommand $command): bool => ($command->arguments[8] ?? null) === 'sh' && str_contains($command->arguments[10], 'systemd-firstboot'));
        $programs = json_decode((string) $manifest->input, true);

        expect($programs['schema'])->toBe(1)
            ->and(array_keys($programs['programs']))->toBe(['bootstrap', 'caddy-package', 'app-dev-prerequisites', 'vp-manager', 'composer-manager', 'app-dev-php-source', 'app-dev-php-8-5', 'app-dev-php-8-4', 'rust-toolchain'])
            ->and($programs['programs']['rust-toolchain'])->toBe(TaskVmBakedPrograms::fingerprint(new RemoteCommand(['bash', '-ceu', TaskVmImageRecipe::InstallRust])))
            ->and($programs['programs']['bootstrap'])->toBe(TaskVmBakedPrograms::fingerprint(app(NodeBootstrapCommandFactory::class)->makeWithPasswordlessSudo($node, 'orbit')))
            ->and($programs['programs']['composer-manager'])->toBe(TaskVmBakedPrograms::fingerprint(app(ComposerToolManager::class)->materializeCommand($node)))
            ->and($programs['programs']['app-dev-php-8-4'])->toBe(TaskVmBakedPrograms::fingerprint(app(RemotePhpPackageManager::class)->appDevPrograms(['8.5', '8.4'])['8.4']))
            ->and(array_slice($manifest->arguments, 5, 4))->toBe(['exec', '--', 'tvm-image-20261010030000', 'install'])
            ->and(Arr::last($lean->arguments))->toContain('systemd-firstboot.service', 'apt-daily.timer', 'LinkLocalAddressing=no', '--ipv4')->not->toContain('docker')
            ->and($check->arguments[7])->toBe('tvm-image-20261010030000-smoke')
            ->and(array_slice($check->arguments, -2))->toBe([TaskVmBakedPrograms::ManifestPath, '1000:1000'])
            ->and($check->arguments[10])->toContain('test "$(id -u orbit):$(id -g orbit)" = "$2"');
    });

    it('stops right after cloud-init when orbit did not get the pinned uid and gid', function (): void {
        $this->incus->fail = 'orbit user';
        $stages = [];

        expect(function () use (&$stages): void {
            ($this->build)($stages);
        })->toThrow(TaskVmException::class, 'stage [orbit-user]');
        expect($stages)->toBe(['render', 'sweep', 'launch', 'cloud-init'])
            ->and($this->incus->instances)->toBe([]);
        $owner = collect($this->incus->commands)->first(static fn (RemoteCommand $command): bool => ($command->arguments[8] ?? null) === 'sh');
        expect(array_slice($owner->arguments, 8))->toBe(['sh', '-euc', 'test "$(id -u orbit):$(id -g orbit)" = "$1"', 'sh', '1000:1000']);
    });

    it('fails the smoke test when a VM from the new image lost the lean boot', function (): void {
        $this->incus->fail = 'lean boot';

        expect(fn () => ($this->build)())->toThrow(TaskVmException::class, 'did not keep the lean boot, the image manifest, or the pinned orbit uid and gid');
        expect($this->incus->images)->toBe([IMAGE_STOCK => ['ubuntu-26.04-vm']])
            ->and($this->incus->instances)->toBe([]);
    });

    it('fails the smoke test when a VM from the new image reboots on its first boot', function (): void {
        $this->incus->boots = 2;

        expect(fn () => ($this->build)())->toThrow(TaskVmException::class, 'failed at stage [smoke]: Incus returned invalid output for the base image smoke VM [tvm-image-');
        expect($this->incus->images)->toBe([IMAGE_STOCK => ['ubuntu-26.04-vm']])
            ->and($this->incus->instances)->toBe([]);
    });

    it('moves the base alias in one request, keeps an image a VM uses and deletes the unused ones', function (): void {
        $this->incus->images += [
            IMAGE_OLD => ['orbit-task-base-20261009030000', TaskVmHost::BaseImage],
            IMAGE_USED => ['orbit-task-base-20261008030000'],
        ];
        $this->incus->instances = ['tvm-3' => IMAGE_USED, 'tvm-4' => IMAGE_OLD];

        ($this->build)();

        $query = collect($this->incus->commands)->first(static fn (RemoteCommand $command): bool => $command->arguments[3] === 'query');
        expect($query->arguments)->toBe(['sudo', '-n', 'incus', 'query', '-X', 'PATCH', '--data', '{"target":"'.IMAGE_NEW.'"}', '--', '/1.0/images/aliases/orbit-task-base?project=orbit-tasks'])
            ->and(array_keys($this->incus->images))->toBe([IMAGE_STOCK, IMAGE_OLD, IMAGE_USED, IMAGE_NEW])
            ->and($this->incus->images[IMAGE_NEW])->toContain(TaskVmHost::BaseImage);

        unset($this->incus->instances['tvm-3'], $this->incus->instances['tvm-4']);
        $this->travel(1)->days();
        ($this->build)();

        expect(array_keys($this->incus->images))->toBe([IMAGE_STOCK, IMAGE_NEW]);
    });

    it('deletes the builder and smoke VMs that an interrupted build left', function (): void {
        $this->incus->instances = ['tvm-image-20261009030000' => IMAGE_STOCK, 'tvm-image-20261009030000-smoke' => null, 'tvm-7' => IMAGE_OLD];

        ($this->build)();

        expect(($this->argv)())->toContain('delete --force -- tvm-image-20261009030000', 'delete --force -- tvm-image-20261009030000-smoke')
            ->not->toContain('delete --force -- tvm-7')
            ->and(array_keys($this->incus->instances))->toBe(['tvm-7']);
    });
});

describe('a failed build', function (): void {
    it('keeps the current image and leaves nothing behind', function (string $fail, string $stage, array $images): void {
        $this->travelTo('2026-10-10 03:00:00');
        $this->incus->images += [IMAGE_OLD => ['orbit-task-base-20261009030000', TaskVmHost::BaseImage]];
        $this->incus->fail = $fail;

        expect(fn () => ($this->build)())->toThrow(fn (TaskVmException $e) => expect([$e->errorCode, $e->status, $e->getMessage()])
            ->toMatchArray([0 => 'task_vm.image_build_failed', 1 => 502])
            ->and($e->getMessage())->toStartWith("The base image build of Node [beast] failed at stage [{$stage}]: "));

        expect($this->incus->instances)->toBe([])
            ->and($this->incus->images)->toBe($images);
    })->with([
        'cloud-init error' => ['cloud-init', 'cloud-init', [IMAGE_STOCK => ['ubuntu-26.04-vm'], IMAGE_OLD => ['orbit-task-base-20261009030000', 'orbit-task-base']]],
        'identity left' => ['clean', 'clean', [IMAGE_STOCK => ['ubuntu-26.04-vm'], IMAGE_OLD => ['orbit-task-base-20261009030000', 'orbit-task-base']]],
        'smoke VM' => ['ssh-keygen', 'smoke', [IMAGE_STOCK => ['ubuntu-26.04-vm'], IMAGE_OLD => ['orbit-task-base-20261009030000', 'orbit-task-base']]],
        'alias move' => ['query', 'promote', [IMAGE_STOCK => ['ubuntu-26.04-vm'], IMAGE_OLD => ['orbit-task-base-20261009030000', 'orbit-task-base']]],
        'prune, after the new image is the base image' => ['image delete', 'prune', [IMAGE_STOCK => ['ubuntu-26.04-vm'], IMAGE_OLD => ['orbit-task-base-20261009030000'], IMAGE_NEW => ['orbit-task-base-20261010030000', 'orbit-task-base']]],
    ]);

    it('lets the owner of a stopped build delete its builder and free the host, and nobody else', function (): void {
        $host = $this->settings->host($this->host->id);
        $lock = Cache::lock('task-vms:image-build:'.$this->host->id, 3600, 'nightly-refresh');
        $lock->get();
        $this->incus->instances = ['tvm-image-20261010030000' => IMAGE_STOCK, 'tvm-5' => IMAGE_OLD];

        app(TaskVmBaseImage::class)->abandon($host, $this->host, 'command-other');
        expect(array_keys($this->incus->instances))->toBe(['tvm-image-20261010030000', 'tvm-5'])
            ->and(Cache::lock('task-vms:image-build:'.$this->host->id, 10)->get())->toBeFalse();

        (new RefreshTaskVmBaseImages)->failed(new RuntimeException('timed out'));
        expect(array_keys($this->incus->instances))->toBe(['tvm-5'])
            ->and(Cache::lock('task-vms:image-build:'.$this->host->id, 10)->get())->toBeTrue();
    });

    it('frees the host when the build fails before its first VM', function (): void {
        app()->instance(SshKeyProvider::class, new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/keys/id_ed25519';
            }

            public function publicKey(): string
            {
                throw new RuntimeException('no key');
            }
        });

        expect(fn () => ($this->build)())->toThrow(TaskVmException::class, 'failed at stage [render]: no key');
        expect(Cache::lock('task-vms:image-build:'.$this->host->id, 10)->get())->toBeTrue();
    });

    it('refuses a second build of the same host while one runs', function (): void {
        $lock = Cache::lock('task-vms:image-build:'.$this->host->id, 60);
        $lock->get();

        expect(fn () => ($this->build)())->toThrow(TaskVmException::class, 'A base image build or sync of Node [beast] is already running.');
        expect($this->incus->commands)->toBe([]);
        $lock->release();
    });
});

describe('task-vms:build-image', function (): void {
    it('prints every stage and the new image', function (): void {
        $this->artisan('task-vms:build-image', ['node' => 'beast'])
            ->expectsOutputToContain('app-dev-prerequisites')
            ->expectsOutputToContain('Node [beast] has the base image [orbit-task-base] (aaaaaaaaaaaa)')
            ->assertSuccessful();
    });

    it('fails with the code of the failed stage', function (): void {
        $this->incus->fail = 'publish';

        $this->artisan('task-vms:build-image', ['node' => (string) $this->host->id])
            ->expectsOutputToContain('[task_vm.image_build_failed] The base image build of Node [beast] failed at stage [publish]')
            ->assertFailed();
    });

    it('refuses a Node that is not a task VM host', function (): void {
        Node::query()->create(['name' => 'shark', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '192.0.2.8', 'wireguard_ip' => '10.44.0.11', 'user' => 'nckrtl']);

        $this->artisan('task-vms:build-image', ['node' => 'shark'])
            ->expectsOutputToContain('[task_vm.unknown_host]')
            ->assertFailed();
        expect($this->incus->commands)->toBe([]);
    });
});

describe('the nightly refresh', function (): void {
    it('is queued on the task VM queue every night at 03:00 while task VMs are enabled', function (): void {
        Artisan::call('schedule:list');
        $refresh = collect(app(Schedule::class)->events())->first(static fn ($event): bool => $event instanceof CallbackEvent && $event->description === RefreshTaskVmBaseImages::class);

        config(['task_vms.enabled' => false]);
        expect($refresh)->not->toBeNull()
            ->and($refresh->expression)->toBe('0 3 * * *')
            ->and($refresh->filtersPass(app()))->toBeFalse()
            ->and([new RefreshTaskVmBaseImages()->connection, new RefreshTaskVmBaseImages()->queue])->toBe(['task-vms', 'task-vms']);

        config(['task_vms.enabled' => true]);
        expect($refresh->filtersPass(app()))->toBeTrue();
    });
});
