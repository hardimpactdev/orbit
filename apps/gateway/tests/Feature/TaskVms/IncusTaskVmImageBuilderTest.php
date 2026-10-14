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
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\IncusTaskVmBaseImage;
use App\Infrastructure\TaskVms\IncusTaskVmImageBuilder;
use App\Infrastructure\TaskVms\TaskVmRuntime;
use App\Infrastructure\TaskVms\TaskVmScripts;
use App\Infrastructure\Tools\ComposerToolManager;
use App\Infrastructure\Tools\VpToolManager;
use App\Jobs\TaskVms\RefreshTaskVmBaseImages;
use App\Jobs\TaskVms\RefreshTaskVmWorkspaceTemplates;
use App\Models\Node;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\IncusGuestCall;
use Tests\Support\TaskVmSettingsBuilder;

require_once __DIR__.'/../../Support/FakeIncusImageHost.php';

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
            new RemoteCommand(TaskVmScripts::bash('install-rust.sh')),
        ];
        $programsRun = array_values(array_filter($this->incus->guest, static fn (IncusGuestCall $call): bool => $call->vm === 'tvm-image-20261010030000' && $call->user === 'orbit' && $call->cwd === '/home/orbit'));
        $launches = array_values(array_filter($this->incus->commands, static fn (RemoteCommand $command): bool => $command->arguments[5] === 'launch'));

        expect($fingerprint)->toBe(IMAGE_NEW)
            ->and($stages)->toBe(['render', 'sweep', 'launch', 'cloud-init', 'orbit-user', 'bootstrap', 'caddy-package', 'app-dev-prerequisites', 'vp-manager', 'composer-manager', 'app-dev-php-source', 'app-dev-php-8-5', 'app-dev-php-8-4', 'rust-toolchain', 'agent-binary', 'single-boot', 'lean-boot', 'clean', 'publish', 'delete-builder', 'smoke', 'promote', 'prune'])
            ->and(array_map(static fn (IncusGuestCall $call): array => [$call->argv, $call->input], $programsRun))
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

        $agent = collect($this->incus->guest)->first(static fn (IncusGuestCall $call): bool => $call->script === 'install-agent.sh');
        $clean = collect($this->incus->guest)->first(static fn (IncusGuestCall $call): bool => $call->argv === ['bash', '-s']);
        expect([$agent->user, $clean->user])->toBe(['root', 'root'])
            ->and(array_slice($agent->argv, -2))->toBe([NodeAgentFootprint::downloadUrl('x86_64'), NodeAgentFootprint::checksum('x86_64')])
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

        $pi = collect($this->incus->guest)->first(static fn (IncusGuestCall $call): bool => $call->input instanceof ProtectedInput);
        expect($stages)->toContain('pi-artifact')
            ->and(array_search('pi-artifact', $stages, true))->toBe(array_search('agent-binary', $stages, true) + 1)
            ->and([$pi->vm, $pi->user, $pi->cwd])->toBe(['tvm-image-'.now()->format('YmdHis'), 'orbit', '/home/orbit'])
            ->and($pi->argv)->toBe(['bash', '-ceu', TaskVmRuntime::InstallPi, '--', $digest]);
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

    it('keeps work off the boot and checks the image identity', function (): void {
        $this->travelTo('2026-10-10 03:00:00');

        ($this->build)();

        $lean = collect($this->incus->guest)->first(static fn (IncusGuestCall $call): bool => $call->argv[0] === 'bash' && $call->argv[1] === '-ceu' && str_contains($call->argv[2], 'systemctl mask'));
        $check = collect($this->incus->guest)->first(static fn (IncusGuestCall $call): bool => $call->script === 'image-check.sh');

        expect([$lean->user, $lean->argv[2]])->toBe(['root', TaskVmScripts::read('lean-boot.sh').'systemctl mask systemd-firstboot.service apt-daily.timer apt-daily-upgrade.timer motd-news.timer unattended-upgrades.service'])
            ->and($lean->argv[2])->toContain('LinkLocalAddressing=no', '--ipv4')->not->toContain('docker')
            ->and([$check->vm, $check->user])->toBe(['tvm-image-20261010030000-smoke', 'root'])
            ->and(array_slice($check->argv, 3))->toBe(['--', 'orbit', '1000:1000', '--lean-boot']);
    });

    it('stops right after cloud-init when orbit did not get the pinned uid and gid', function (): void {
        $this->incus->fail = 'orbit user';
        $stages = [];

        expect(function () use (&$stages): void {
            ($this->build)($stages);
        })->toThrow(TaskVmException::class, 'stage [orbit-user]');
        expect($stages)->toBe(['render', 'sweep', 'launch', 'cloud-init'])
            ->and($this->incus->instances)->toBe([]);
        $owner = collect($this->incus->guest)->first(static fn (IncusGuestCall $call): bool => $call->argv[0] === 'sh');
        expect($owner->argv)->toBe(['sh', '-euc', 'test "$(id -u -- "$2"):$(id -g -- "$2")" = "$1"', 'sh', '1000:1000', 'orbit']);
    });

    it('fails the smoke test when a VM from the new image lost the lean boot', function (): void {
        $this->incus->fail = 'lean boot';

        expect(fn () => ($this->build)())->toThrow(TaskVmException::class, 'did not keep the lean boot or the pinned orbit uid and gid');
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

    it('queues no template refresh: whoever builds queues one when it is done with every host', function (): void {
        Queue::fake();

        ($this->build)();

        Queue::assertNotPushed(RefreshTaskVmWorkspaceTemplates::class);
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

        app(IncusTaskVmBaseImage::class)->abandon($host, $this->host, 'command-other');
        expect(array_keys($this->incus->instances))->toBe(['tvm-image-20261010030000', 'tvm-5'])
            ->and(Cache::lock('task-vms:image-build:'.$this->host->id, 10)->get())->toBeFalse();

        app(IncusTaskVmBaseImage::class)->abandon($host, $this->host, 'nightly-refresh');
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

// Progress, failure reporting and template refresh now belong to TaskVmImageBuildChainTest.
describe('task-vms:build-image', function (): void {
    it('refuses a Node that is not a task VM host', function (): void {
        Node::query()->create(['name' => 'shark', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '192.0.2.8', 'wireguard_ip' => '10.44.0.11', 'user' => 'nckrtl']);

        $this->artisan('task-vms:build-image', ['node' => 'shark'])
            ->expectsOutputToContain('[task_vm.unknown_host]')
            ->assertFailed();
        expect($this->incus->commands)->toBe([]);
    });
});

describe('the nightly refresh', function (): void {
    it('is queued on the image queue every night at 03:00 while task VMs are enabled', function (): void {
        Artisan::call('schedule:list');
        $refresh = collect(app(Schedule::class)->events())->first(static fn ($event): bool => $event instanceof CallbackEvent && $event->description === RefreshTaskVmBaseImages::class);

        config(['task_vms.enabled' => false]);
        expect($refresh)->not->toBeNull()
            ->and($refresh->expression)->toBe('0 3 * * *')
            ->and($refresh->filtersPass(app()))->toBeFalse()
            ->and([new RefreshTaskVmBaseImages()->connection, new RefreshTaskVmBaseImages()->queue])->toBe(['gateway', 'builds']);

        config(['task_vms.enabled' => true]);
        expect($refresh->filtersPass(app()))->toBeTrue();
    });
});
