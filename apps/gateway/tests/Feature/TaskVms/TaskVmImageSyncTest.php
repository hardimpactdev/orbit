<?php

declare(strict_types=1);

use App\Domain\Clusters\ClusterState;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmImageBuildStep;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\Firewall\NodeFirewallDesiredRules;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\IncusTaskVmImageSync;
use App\Infrastructure\TaskVms\TaskVmImageBuildChain;
use App\Infrastructure\TaskVms\TaskVmImageBuildSteps;
use App\Infrastructure\TaskVms\TaskVmImagePeers;
use App\Jobs\TaskVms\ImageBuild\LaunchImageBuilder;
use App\Jobs\TaskVms\ImageBuild\SyncBuiltImage;
use App\Jobs\TaskVms\RefreshTaskVmBaseImages;
use App\Jobs\TaskVms\RefreshTaskVmWorkspaceTemplates;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\TaskVmImageBuild;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Support\FakeIncusFleet;
use Tests\Support\FakeIncusFleetHost;
use Tests\Support\TaskVmSettingsBuilder;

const SYNC_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeGatewayPublicKeyMaterial0123456789abcd orbit@gateway';

const SYNC_NEW = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

const SYNC_OLD = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

const SYNC_SHARK = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

const SYNC_STOCK = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';

/** Configures `$nodes` as task VM hosts, in order. */
function sync_hosts(Node ...$nodes): TaskVmSettings
{
    $settings = TaskVmSettingsBuilder::bind(['hosts' => array_map(
        static fn (Node $node, int $index): TaskVmHost => TaskVmSettingsBuilder::host($node->id, ['cidr' => '10.251.'.(77 + $index).'.0/24']),
        $nodes,
        array_keys($nodes),
    )]);

    return $settings;
}

function sync_node(string $name, string $wireguard, ?string $lan, ?Cluster $cluster): Node
{
    return Node::query()->create([
        'name' => $name, 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => $wireguard, 'wireguard_ip' => $wireguard, 'lan_ip' => $lan, 'cluster_id' => $cluster?->id, 'user' => 'nckrtl',
    ]);
}

beforeEach(function (): void {
    Sleep::fake();
    $this->travelTo('2026-10-11 03:00:00');
    $this->cluster = Cluster::query()->create(['name' => 'dev', 'state' => ClusterState::Active]);
    $this->beast = sync_node('beast', '10.44.0.7', '192.168.6.20', $this->cluster);
    $this->shark = sync_node('shark', '10.44.0.11', '192.168.6.40', $this->cluster);
    $this->settings = sync_hosts($this->beast, $this->shark);
    $this->fleet = new FakeIncusFleet;
    $this->fleet->host('10.44.0.7')->lan = '192.168.6.20';
    $this->fleet->host('10.44.0.11')->lan = '192.168.6.40';
    $this->fleet->host('10.44.0.7')->images = [SYNC_STOCK => [TaskVmHost::SourceImage], SYNC_OLD => ['orbit-task-base-20261010030000', TaskVmHost::BaseImage]];
    $this->fleet->host('10.44.0.11')->images = [SYNC_SHARK => ['orbit-task-base-20261010030500', TaskVmHost::BaseImage]];
    app()->instance(SshExecutor::class, $this->fleet);
    app()->instance(SshKeyProvider::class, new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/keys/id_ed25519';
        }

        public function publicKey(): string
        {
            return SYNC_KEY;
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
    $this->link = fn () => app(TaskVmImagePeers::class)->converge();
});

describe('image peers', function (): void {
    it('lets hosts in one Cluster send images to each other on the LAN with restricted keys', function (): void {
        $lines = [];
        app(TaskVmImagePeers::class)->converge(function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $beast = $this->fleet->host('10.44.0.7');
        $shark = $this->fleet->host('10.44.0.11');
        $receiver = 'command="sudo -n /usr/local/libexec/orbit/task-vm-image-receive orbit-tasks"';
        expect($beast->authorizedKeys)->toBe(["from=\"192.168.6.40\",restrict,{$receiver} {$shark->transferKey} orbit-task-image-{$this->shark->id}"])
            ->and($shark->authorizedKeys)->toBe(["from=\"192.168.6.20\",restrict,{$receiver} {$beast->transferKey} orbit-task-image-{$this->beast->id}"])
            ->and($beast->receiver)->toBe(file_get_contents(resource_path('task-vms/image-receive.sh')))
            ->and($shark->receiver)->toBe($beast->receiver)
            ->and($lines)->toBe([
                'Node [beast] accepts base images from 1 other hosts.',
                'Node [shark] accepts base images from 1 other hosts.',
                'Node [beast] reaches Node [shark] on 192.168.6.40.',
                'Node [shark] reaches Node [beast] on 192.168.6.20.',
            ])
            ->and($this->fleet->argv('10.44.0.7'))->toContain('cat -- /etc/ssh/ssh_host_ed25519_key.pub');

        $keys = [$beast->transferKey, $shark->transferKey, $beast->authorizedKeys, $shark->authorizedKeys];
        ($this->link)();

        expect([$beast->transferKey, $shark->transferKey, $beast->authorizedKeys, $shark->authorizedKeys])->toBe($keys);
    });

    it('uses WireGuard addresses for a peer in another Cluster', function (): void {
        $other = Cluster::query()->create(['name' => 'remote', 'state' => ClusterState::Active]);
        $far = sync_node('far', '10.44.0.30', '192.168.9.5', $other);
        $this->fleet->host('10.44.0.30')->lan = '192.168.9.5';
        sync_hosts($this->beast, $this->shark, $far);

        ($this->link)();

        $lines = $this->fleet->host('10.44.0.7')->authorizedKeys;
        expect($lines)->toHaveCount(2)
            ->and($lines[0])->toStartWith('from="192.168.6.40",restrict,')
            ->and($lines[1])->toStartWith('from="10.44.0.30",restrict,')
            ->and($this->fleet->host('10.44.0.30')->authorizedKeys[0])->toStartWith('from="10.44.0.7",restrict,')
            ->and(TaskVmImagePeers::route($this->beast, $far))->toBe(['10.44.0.7', '10.44.0.30'])
            ->and(TaskVmImagePeers::route($this->beast, $this->shark))->toBe(['192.168.6.20', '192.168.6.40']);
    });

    it('renders a key line that runs only the receiver, only from the peer address', function (): void {
        $line = app(TaskVmImagePeers::class)->keyLine($this->shark, $this->beast, 'orbit-tasks', 'ssh-ed25519 AAAAkey');

        expect($line)->toBe("from=\"192.168.6.40\",restrict,command=\"sudo -n /usr/local/libexec/orbit/task-vm-image-receive orbit-tasks\" ssh-ed25519 AAAAkey orbit-task-image-{$this->shark->id}");
    });

    it('keeps every other authorized key and replaces the line of a former peer', function (): void {
        $beast = $this->fleet->host('10.44.0.7');
        $beast->authorizedKeys = [
            'ssh-ed25519 AAAAnick nick@mac',
            'from="192.168.6.99",restrict,command="sudo -n /usr/local/libexec/orbit/task-vm-image-receive orbit-tasks" ssh-ed25519 AAAAold orbit-task-image-99',
        ];

        ($this->link)();

        expect($beast->authorizedKeys)->toHaveCount(2)
            ->and($beast->authorizedKeys[0])->toBe('ssh-ed25519 AAAAnick nick@mac')
            ->and($beast->authorizedKeys[1])->toEndWith("orbit-task-image-{$this->shark->id}");
    });

    it('fails loudly when a host cannot reach the receiver of a peer', function (): void {
        $this->fleet->host('10.44.0.11')->fail = 'ssh connect';

        expect(fn () => ($this->link)())->toThrow(fn (TaskVmException $e) => expect([$e->errorCode, $e->status])->toBe(['task_vm.host_command_failed', 502])
            ->and($e->getMessage())->toBe("Node [beast] cannot reach the image receiver of Node [shark] on 192.168.6.40: exit code [255].\nssh: connect to host 192.168.6.40 port 22: Connection refused"));
    });

    it('lists no task VM firewall rule among the rules Orbit intends on the host', function (): void {
        ($this->link)();

        expect(collect(app(NodeFirewallDesiredRules::class)->forNode($this->beast))->pluck('role'))->not->toContain('task-vms');
    });
});

describe('a sync', function (): void {
    describe('between different host image aliases', function (): void {
        beforeEach(function (): void {
            $this->settings = TaskVmSettingsBuilder::bind(['hosts' => [
                TaskVmSettingsBuilder::host($this->beast->id, ['imageAlias' => 'beast-base']),
                TaskVmSettingsBuilder::host($this->shark->id, ['imageAlias' => 'shark-base', 'cidr' => '10.251.78.0/24']),
            ]]);
            $this->fleet->host('10.44.0.7')->images = [SYNC_OLD => ['beast-base-20261010030000-91', 'beast-base']];
            $this->fleet->host('10.44.0.11')->images = [];
            ($this->link)();
            $this->copyToShark = fn (string $fingerprint): bool => app(IncusTaskVmImageSync::class)->sync(
                $this->settings->host($this->beast->id), $this->beast,
                $this->settings->host($this->shark->id), $this->shark, $fingerprint,
            );
        });

        it('copies the image with a receiver-local dated alias and preserves its build suffix', function (): void {
            expect(($this->copyToShark)(SYNC_OLD))->toBeTrue()
                ->and($this->fleet->host('10.44.0.11')->received)->toBe([SYNC_OLD])
                ->and($this->fleet->host('10.44.0.11')->images)->toBe([
                    SYNC_OLD => ['shark-base-20261010030000-91', 'shark-base'],
                ])
                ->and($this->fleet->host('10.44.0.7')->images)->toBe([
                    SYNC_OLD => ['beast-base-20261010030000-91', 'beast-base'],
                ]);
        });

        it('prunes an obsolete received image after copying the next image', function (): void {
            expect(($this->copyToShark)(SYNC_OLD))->toBeTrue();
            $this->fleet->host('10.44.0.7')->images[SYNC_NEW] = ['beast-base-20261011030000-92', 'beast-base'];

            expect(($this->copyToShark)(SYNC_NEW))->toBeTrue()
                ->and($this->fleet->host('10.44.0.11')->received)->toBe([SYNC_OLD, SYNC_NEW])
                ->and($this->fleet->host('10.44.0.11')->images)->toBe([
                    SYNC_NEW => ['shark-base-20261011030000-92', 'shark-base'],
                ]);
        });

        it('can copy back from the receiver as source after the original source loses the image', function (): void {
            expect(($this->copyToShark)(SYNC_OLD))->toBeTrue();
            $this->fleet->host('10.44.0.7')->images = [];

            expect(app(IncusTaskVmImageSync::class)->sync(
                $this->settings->host($this->shark->id), $this->shark,
                $this->settings->host($this->beast->id), $this->beast, SYNC_OLD,
            ))->toBeTrue()
                ->and($this->fleet->host('10.44.0.7')->received)->toBe([SYNC_OLD])
                ->and($this->fleet->host('10.44.0.7')->images)->toBe([
                    SYNC_OLD => ['beast-base-20261010030000-91', 'beast-base'],
                ]);
        });
    });

    it('reads each host image alias when finding the source and an already current target', function (): void {
        $settings = TaskVmSettingsBuilder::bind(['hosts' => [
            TaskVmSettingsBuilder::host($this->beast->id, ['imageAlias' => 'beast-base']),
            TaskVmSettingsBuilder::host($this->shark->id, ['cidr' => '10.251.78.0/24', 'imageAlias' => 'shark-base']),
        ]]);
        $this->fleet->host('10.44.0.7')->images = [SYNC_OLD => ['beast-base-20261010030000', 'beast-base']];
        $this->fleet->host('10.44.0.11')->images = [SYNC_OLD => ['beast-base-20261010030000', 'shark-base']];
        $sync = app(IncusTaskVmImageSync::class);
        expect($sync->current($settings->host($this->beast->id), $this->beast))->toBe(SYNC_OLD)
            ->and($sync->current($settings->host($this->shark->id), $this->shark))->toBe(SYNC_OLD)
            ->and($sync->sync($settings->host($this->beast->id), $this->beast, $settings->host($this->shark->id), $this->shark, SYNC_OLD))->toBeFalse()
            ->and($this->fleet->host('10.44.0.11')->received)->toBe([]);
    });

    it('exports the source image, streams it to the target, checks its fingerprint, smoke-tests it, promotes it, and prunes the old one', function (): void {
        ($this->link)();
        $stages = [];

        $copied = app(IncusTaskVmImageSync::class)->sync($this->settings->host($this->beast->id), $this->beast, $this->settings->host($this->shark->id), $this->shark, SYNC_OLD, function (string $stage, float $seconds) use (&$stages): void {
            $stages[] = $stage;
        });

        $shark = $this->fleet->host('10.44.0.11');
        $beast = $this->fleet->host('10.44.0.7');
        $directory = "/var/lib/orbit-task-vm-image/export/node-{$this->shark->id}";
        expect($copied)->toBeTrue()
            ->and($stages)->toBe(['inspect', 'sweep', 'export', 'transfer', 'verify', 'smoke', 'promote', 'prune'])
            ->and($shark->images)->toBe([SYNC_OLD => ['orbit-task-base-20261010030000', TaskVmHost::BaseImage]])
            ->and($shark->received)->toBe([SYNC_OLD])
            ->and($shark->instances)->toBe([])
            ->and($beast->exports)->toBe([])
            ->and($this->fleet->argv('10.44.0.7'))->toContain("rm -rf -- {$directory}")
            ->and($this->fleet->argv('10.44.0.11'))->toContain(
                'incus --project orbit-tasks image alias create -- orbit-task-base-20261010030000 '.SYNC_OLD,
                'incus --project orbit-tasks launch --vm --config limits.cpu=2 --config limits.memory=4GiB --device root,size=20GiB --device eth0,network=orbittask0 --device eth0,security.port_isolation=true -- orbit-task-base-20261010030000 tvm-image-20261011030000-sync-smoke',
            )
            ->and($beast->images)->toBe([SYNC_STOCK => [TaskVmHost::SourceImage], SYNC_OLD => ['orbit-task-base-20261010030000', TaskVmHost::BaseImage]]);
        $send = collect($this->fleet->commands)->first(static fn (array $command): bool => str_contains(implode(' ', $command[1]), 'UserKnownHostsFile') && $command[1][6] !== '/dev/null');
        expect($send[0])->toBe('10.44.0.7')
            ->and(array_slice($send[1], 6))->toBe(["{$directory}/image.tar", '/etc/orbit/task-vms/image-transfer/id_ed25519', '192.168.6.20', $shark->hostKey, 'nckrtl', '192.168.6.40'])
            // The Gateway only starts the command: no image passes through it.
            ->and($send[2])->toBeNull();

        expect(app(IncusTaskVmImageSync::class)->sync($this->settings->host($this->beast->id), $this->beast, $this->settings->host($this->shark->id), $this->shark, SYNC_OLD))->toBeFalse();
    });

    it('goes on when the transfer reports a failure but the image arrived', function (): void {
        ($this->link)();
        $this->fleet->host('10.44.0.11')->fail = 'transfer reply';
        Log::spy();

        $copied = app(IncusTaskVmImageSync::class)->sync($this->settings->host($this->beast->id), $this->beast, $this->settings->host($this->shark->id), $this->shark, SYNC_OLD);

        expect($copied)->toBeTrue()
            ->and($this->fleet->host('10.44.0.11')->images[SYNC_OLD])->toContain(TaskVmHost::BaseImage);
        Log::shouldHaveReceived('warning')->once()->withArgs(static fn (string $message, array $context): bool => $message === 'The task VM base image transfer failed, but the target has the image.'
            && $context['exit_code'] === 255 && str_contains($context['stderr'], 'closed by remote host'));
    });

    it('fails and keeps the current image', function (Closure $break, string $stage, string $reason): void {
        ($this->link)();
        $break($this->fleet->host('10.44.0.11'), $this->fleet->host('10.44.0.7'));
        $source = $this->fleet->host('10.44.0.7')->images;

        expect(fn () => app(IncusTaskVmImageSync::class)->sync($this->settings->host($this->beast->id), $this->beast, $this->settings->host($this->shark->id), $this->shark, SYNC_OLD))
            ->toThrow(fn (TaskVmException $e) => expect([$e->errorCode, $e->status])->toBe(['task_vm.image_sync_failed', 502])
                ->and($e->getMessage())->toStartWith("The base image sync of Node [shark] from Node [beast] failed at stage [{$stage}]: ")
                ->and($e->getMessage())->toContain($reason));

        expect($this->fleet->host('10.44.0.11')->images)->toBe([SYNC_SHARK => ['orbit-task-base-20261010030500', TaskVmHost::BaseImage]])
            ->and($this->fleet->host('10.44.0.11')->instances)->toBe([])
            ->and($this->fleet->host('10.44.0.7')->images)->toBe($source)
            ->and($this->fleet->host('10.44.0.7')->exports)->toBe([])
            ->and($this->fleet->argv('10.44.0.7'))->each->not->toMatch('/ (delete|launch|alias|publish) /');
    })->with([
        'export that fails' => [fn ($shark, $beast) => $beast->fail = 'image export', 'export', 'Error: image export failed'],
        'transfer that breaks' => [fn ($shark) => $shark->fail = 'transfer', 'transfer', "exit code [255].\nclient_loop: send disconnect: Broken pipe"],
        'fingerprint mismatch' => [fn ($shark) => $shark->copyAs = 'f'.str_repeat('1', 63), 'transfer', 'the receiver imported the image [f111'],
        'target without the source key' => [fn ($shark) => $shark->authorizedKeys = [], 'transfer', 'Permission denied (publickey)'],
        'address that presents another host key' => [fn ($shark) => $shark->sshdKey = FakeIncusFleetHost::publicKey(), 'transfer', 'Host key verification failed'],
        'smoke VM without a host key' => [fn ($shark) => $shark->fail = 'ssh-keygen', 'smoke', 'exec ssh-keygen'],
        'smoke VM that rebooted' => [fn ($shark) => $shark->boots = 2, 'smoke', 'it rebooted during its first boot'],
    ]);
});

describe('a sync that meets other work', function (): void {
    it('refuses a host whose sync is running', function (): void {
        ($this->link)();
        $lock = Cache::lock('task-vms:image-sync:'.$this->shark->id, 60);
        $lock->get();
        $commands = count($this->fleet->argv('10.44.0.11'));

        expect(fn () => app(IncusTaskVmImageSync::class)->sync($this->settings->host($this->beast->id), $this->beast, $this->settings->host($this->shark->id), $this->shark, SYNC_OLD))
            ->toThrow(TaskVmException::class, 'A base image sync or build admission of Node [shark] is already running.');
        expect($this->fleet->argv('10.44.0.11'))->toHaveCount($commands);
        $lock->release();
    });

    it('refuses a running build row without touching either host and frees its sync lock', function (): void {
        ($this->link)();
        $build = TaskVmImageBuild::query()->create(['host_node_id' => $this->shark->id]);
        $sourceCommands = $this->fleet->argv('10.44.0.7');
        $targetCommands = $this->fleet->argv('10.44.0.11');
        $sync = app(IncusTaskVmImageSync::class);

        expect(fn () => $sync->sync($this->settings->host($this->beast->id), $this->beast, $this->settings->host($this->shark->id), $this->shark, SYNC_OLD))
            ->toThrow(TaskVmException::class, 'A base image build of Node [shark] is already running.');
        expect($this->fleet->argv('10.44.0.7'))->toBe($sourceCommands);
        expect($this->fleet->argv('10.44.0.11'))->toBe($targetCommands);

        $build->update(['state' => TaskVmImageBuildState::Failed]);
        expect($sync->sync($this->settings->host($this->beast->id), $this->beast, $this->settings->host($this->shark->id), $this->shark, SYNC_OLD))->toBeTrue();
    });

    it('refuses build admission after the sync row check through promotion', function (string $operation, string $stage): void {
        ($this->link)();
        Bus::fake();
        $failed = $operation === 'resume' ? TaskVmImageBuild::query()->create([
            'host_node_id' => $this->shark->id, 'state' => TaskVmImageBuildState::Failed,
            'current_step' => TaskVmImageBuildStep::UpgradePackages,
        ]) : null;
        if ($failed !== null) {
            $this->fleet->host('10.44.0.11')->instances[$failed->builder_vm_name] = SYNC_SHARK;
        }
        $attempted = false;
        $chain = app(TaskVmImageBuildChain::class);
        $progress = function (string $finished) use ($operation, $stage, $failed, $chain, &$attempted): void {
            if ($finished !== $stage) {
                return;
            }
            $attempted = true;
            $commands = $this->fleet->commands;
            expect(fn () => $operation === 'start' ? $chain->start($this->shark) : $chain->resume($this->shark, $failed->id))
                ->toThrow(TaskVmException::class, 'A base image sync or build admission of Node [shark] is already running.');
            expect($this->fleet->commands)->toBe($commands);
            expect(TaskVmImageBuild::query()->where('state', TaskVmImageBuildState::Running)->exists())->toBeFalse();
            Bus::assertNothingDispatched();
        };

        expect(app(IncusTaskVmImageSync::class)->sync($this->settings->host($this->beast->id), $this->beast, $this->settings->host($this->shark->id), $this->shark, SYNC_OLD, $progress))->toBeTrue();
        expect($attempted)->toBeTrue();
        expect($this->fleet->host('10.44.0.11')->images[SYNC_OLD])->toContain(TaskVmHost::BaseImage);
    })->with(['start', 'resume'])->with(['inspect', 'transfer', 'smoke', 'promote']);

    it('lets only the interrupted sync owner clean its smoke VM and release the sync lock', function (): void {
        $smoke = 'tvm-image-20261011030000-sync-smoke';
        $this->fleet->host('10.44.0.11')->instances = [$smoke => SYNC_SHARK];
        $lock = Cache::lock('task-vms:image-sync:'.$this->shark->id, 1500, 'sync-owner');
        $lock->get();
        $sync = app(IncusTaskVmImageSync::class);
        $host = $this->settings->host($this->shark->id);

        $sync->abandon($host, $this->shark, 'another-sync');
        expect($this->fleet->host('10.44.0.11')->instances)->toHaveKey($smoke);
        expect(Cache::lock('task-vms:image-sync:'.$this->shark->id, 10)->get())->toBeFalse();

        $sync->abandon($host, $this->shark, 'sync-owner');
        expect($this->fleet->host('10.44.0.11')->instances)->toBe([]);
        $next = Cache::lock('task-vms:image-sync:'.$this->shark->id, 10);
        expect($next->get())->toBeTrue();
        $next->release();
    });

    it('keeps the old image while a task VM on the host uses it', function (): void {
        ($this->link)();
        $this->fleet->host('10.44.0.11')->instances = ['tvm-7' => SYNC_SHARK];

        app(IncusTaskVmImageSync::class)->sync($this->settings->host($this->beast->id), $this->beast, $this->settings->host($this->shark->id), $this->shark, SYNC_OLD);

        expect($this->fleet->host('10.44.0.11')->images)->toBe([
            SYNC_SHARK => ['orbit-task-base-20261010030500'],
            SYNC_OLD => ['orbit-task-base-20261010030000', TaskVmHost::BaseImage],
        ])->and($this->fleet->host('10.44.0.11')->instances)->toBe(['tvm-7' => SYNC_SHARK]);
    });

    it('keeps the copy when the alias moved but the reply was lost', function (): void {
        ($this->link)();
        $this->fleet->host('10.44.0.11')->fail = 'query reply';

        expect(fn () => app(IncusTaskVmImageSync::class)->sync($this->settings->host($this->beast->id), $this->beast, $this->settings->host($this->shark->id), $this->shark, SYNC_OLD))
            ->toThrow(TaskVmException::class, 'failed at stage [promote]');
        expect($this->fleet->host('10.44.0.11')->images[SYNC_OLD])->toContain(TaskVmHost::BaseImage);
    });

    it('reports a configured host whose Node is inactive or gone, and still syncs the others', function (): void {
        $far = sync_node('far', '10.44.0.30', '192.168.6.50', $this->cluster);
        $this->fleet->host('10.44.0.30')->lan = '192.168.6.50';
        $settings = sync_hosts($this->beast, $this->shark, $far);
        ($this->link)();
        $this->shark->update(['status' => LifecycleStatus::Removing]);
        TaskVmSettingsBuilder::bind(['hosts' => [...$settings->hosts, TaskVmSettingsBuilder::host(999999, ['cidr' => '10.251.90.0/24'])]]);

        $results = app(IncusTaskVmImageSync::class)->distribute($settings->host($this->beast->id), $this->beast, SYNC_OLD);

        expect(array_map(static fn ($result): array => [$result->nodeName, $result->errorCode], $results))->toBe([
            ['shark', 'task_vm.unknown_host'],
            ['far', null],
            ['999999', 'task_vm.unknown_host'],
        ])->and($this->fleet->host('10.44.0.11')->images)->toBe([SYNC_SHARK => ['orbit-task-base-20261010030500', TaskVmHost::BaseImage]]);
    });
});

describe('queued image distribution', function (): void {
    it('queues a sync to every other host and then one template refresh after promotion', function (): void {
        Bus::fake();
        $build = TaskVmImageBuild::query()->create(['host_node_id' => $this->beast->id, 'image_fingerprint' => SYNC_OLD]);
        app(TaskVmImageBuildSteps::class)->run($build, TaskVmImageBuildStep::Promote, null);
        Bus::assertDispatched(SyncBuiltImage::class, fn ($job): bool => $job->buildId === $build->id && $job->hostNodeId === $this->shark->id && $job->chainQueue === 'builds');
        Bus::assertChained([
            SyncBuiltImage::class,
            RefreshTaskVmWorkspaceTemplates::class,
        ]);
    });

    it('isolates a target that disappears or becomes inactive after enqueue in the real queued chain', function (?LifecycleStatus $status): void {
        Log::spy();
        $far = sync_node('far', '10.44.0.30', '192.168.6.50', $this->cluster);
        $this->fleet->host('10.44.0.30')->lan = '192.168.6.50';
        sync_hosts($this->beast, $this->shark, $far);
        $this->fleet->host('10.44.0.30')->images = [SYNC_SHARK => ['orbit-task-base-20261010030500', TaskVmHost::BaseImage]];
        ($this->link)();
        $build = TaskVmImageBuild::query()->create([
            'host_node_id' => $this->beast->id,
            'image_fingerprint' => SYNC_OLD,
            'state' => TaskVmImageBuildState::Succeeded,
        ]);
        app(TaskVmImageBuildSteps::class)->run($build, TaskVmImageBuildStep::Promote, null);
        expect(DB::table('jobs')->count())->toBe(1);
        $commands = $this->fleet->argv('10.44.0.11');
        if ($status === null) {
            $this->shark->delete();
        } else {
            $this->shark->update(['status' => $status]);
        }
        $processed = [];
        Queue::after(function (JobProcessed $event) use (&$processed): void {
            $processed[] = $event->job->resolveName();
        });
        Artisan::call('queue:work', ['connection' => 'gateway', '--queue' => 'builds', '--stop-when-empty' => true, '--tries' => 1, '--sleep' => 0, '--memory' => 4096]);

        expect($processed)->toBe([SyncBuiltImage::class, SyncBuiltImage::class, RefreshTaskVmWorkspaceTemplates::class])
            ->and($this->fleet->argv('10.44.0.11'))->toBe($commands)
            ->and($this->fleet->host('10.44.0.11')->images)->toBe([SYNC_SHARK => ['orbit-task-base-20261010030500', TaskVmHost::BaseImage]])
            ->and($this->fleet->host('10.44.0.30')->images[SYNC_OLD])->toContain(TaskVmHost::BaseImage)
            ->and($build->refresh()->state)->toBe(TaskVmImageBuildState::Succeeded)
            ->and(DB::table('jobs')->count())->toBe(0)
            ->and(DB::table('failed_jobs')->count())->toBe(1)
            ->and(DB::table('failed_jobs')->value('exception'))->toContain('task_vm.image_sync_failed');
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => $message === 'Task VM base image sync failed.'
            && $context['build_id'] === $build->id
            && $context['host_node_id'] === $this->shark->id
            && $context['node'] === ($status === null ? (string) $this->shark->id : 'shark')
            && $context['error_code'] === 'task_vm.unknown_host');
    })->with([
        'deleted target' => [null],
        'removing target' => [LifecycleStatus::Removing],
        'failed target' => [LifecycleStatus::Failed],
        'provisioning target' => [LifecycleStatus::Provisioning],
    ]);

    it('preserves a queued transfer failure and recovers continuation across rejection or crash', function (string $interruption): void {
        $far = sync_node('far', '10.44.0.30', '192.168.6.50', $this->cluster);
        $this->fleet->host('10.44.0.30')->lan = '192.168.6.50';
        sync_hosts($this->beast, $this->shark, $far);
        $this->fleet->host('10.44.0.30')->images = [SYNC_SHARK => ['orbit-task-base-20261010030500', TaskVmHost::BaseImage]];
        ($this->link)();
        $this->fleet->host('10.44.0.11')->fail = 'transfer';
        $build = TaskVmImageBuild::query()->create(['host_node_id' => $this->beast->id, 'image_fingerprint' => SYNC_OLD, 'state' => TaskVmImageBuildState::Succeeded]);
        app(TaskVmImageBuildSteps::class)->run($build, TaskVmImageBuildStep::Promote, null);
        $processed = [];
        Queue::after(function (JobProcessed $event) use (&$processed): void {
            $processed[] = $event->job->resolveName();
        });
        if ($interruption === 'rejected enqueue') {
            Queue::createPayloadUsing(static function (string $connection, string $queue, array $payload): array {
                throw new RuntimeException('successor enqueue rejected');
            });
            try {
                Artisan::call('queue:work', ['connection' => 'gateway', '--queue' => 'builds', '--once' => true, '--tries' => 1]);
            } finally {
                Queue::createPayloadUsing(null);
            }
            expect(DB::table('jobs')->count())->toBe(1)
                ->and(DB::table('failed_jobs')->value('exception'))->toContain('task_vm.image_sync_failed', 'stage [transfer]');
        } elseif ($interruption !== 'none') {
            $delivery = Queue::connection('gateway')->pop('builds');
            $command = unserialize($delivery->payload()['data']['command']);
            $command->setJob($delivery);
            app()->call([$command, 'handle']);
            expect(DB::table('failed_jobs')->count())->toBe(1);
            expect(DB::table('failed_jobs')->value('exception'))->toContain('task_vm.image_sync_failed', 'stage [transfer]');
            if ($interruption === 'crash after enqueue') {
                $command->dispatchNextJobInChain();
            }
            // Worker dies without acknowledging. The serialized delivery still contains its chain.
            DB::table('jobs')->where('id', $delivery->getJobId())->update(['reserved_at' => now()->subHours(2)->timestamp]);
        }
        // Prove redelivery does not repeat the failed transfer even if the host would now answer.
        if ($interruption !== 'none') {
            $this->fleet->host('10.44.0.11')->fail = null;
        }
        $commands = array_filter($this->fleet->argv('10.44.0.11'), static fn (string $command): bool => ! str_contains($command, ' list '));
        Artisan::call('queue:work', ['connection' => 'gateway', '--queue' => 'builds', '--stop-when-empty' => true, '--tries' => 1, '--sleep' => 0, '--memory' => 4096]);
        if ($interruption !== 'none') {
            // Template refresh may inspect images/volumes; it must not repeat transfer or mutation.
            expect(array_filter($this->fleet->argv('10.44.0.11'), static fn (string $command): bool => ! str_contains($command, ' list ')))->toBe($commands);
        }
        expect($processed)->toContain(SyncBuiltImage::class, RefreshTaskVmWorkspaceTemplates::class)
            ->and(DB::table('jobs')->count())->toBe(0)
            ->and(DB::table('failed_jobs')->count())->toBe(1)
            ->and(DB::table('failed_jobs')->value('exception'))->toContain('task_vm.image_sync_failed', 'stage [transfer]')
            ->and($build->refresh()->state)->toBe(TaskVmImageBuildState::Succeeded);
        expect($this->fleet->host('10.44.0.11')->images)->toBe([SYNC_SHARK => ['orbit-task-base-20261010030500', TaskVmHost::BaseImage]])
            ->and($this->fleet->host('10.44.0.30')->images[SYNC_OLD])->toContain(TaskVmHost::BaseImage)
            ->and($this->fleet->host('10.44.0.7')->images[SYNC_OLD])->toContain(TaskVmHost::BaseImage);
    })->with(['none', 'rejected enqueue', 'crash before enqueue', 'crash after enqueue']);
});

describe('task-vms:sync-image', function (): void {
    it('syncs a host from the first configured host, and then finds it current', function (): void {
        ($this->link)();
        Queue::fake();

        $this->artisan('task-vms:sync-image', ['node' => 'shark'])
            ->expectsOutputToContain('transfer')
            ->expectsOutputToContain('Node [shark] has the base image [orbit-task-base] (bbbbbbbbbbbb) of Node [beast] after')
            ->assertSuccessful();
        Queue::assertPushed(RefreshTaskVmWorkspaceTemplates::class, 1);
        // A host that already has the image needs no refresh. A new fake and no unique lock, so a refresh would show.
        Cache::flush();
        Queue::fake();
        $this->artisan('task-vms:sync-image', ['node' => 'shark'])
            ->expectsOutputToContain('Node [shark] already has the base image [orbit-task-base] (bbbbbbbbbbbb) of Node [beast].')
            ->assertSuccessful();
        Queue::assertNotPushed(RefreshTaskVmWorkspaceTemplates::class);
    });

    it('copies from another host with --from, and refuses a source without a base image', function (): void {
        ($this->link)();

        $this->artisan('task-vms:sync-image', ['node' => 'beast', '--from' => 'shark'])->assertSuccessful();
        expect($this->fleet->host('10.44.0.7')->images[SYNC_SHARK])->toContain(TaskVmHost::BaseImage);

        $this->fleet->host('10.44.0.7')->images = [];
        $this->artisan('task-vms:sync-image', ['node' => 'shark'])
            ->expectsOutputToContain('[task_vm.base_image_missing] Node [beast] has no base image [orbit-task-base].')
            ->assertFailed();
        $this->artisan('task-vms:sync-image', ['node' => 'beast'])
            ->expectsOutputToContain('[task_vm.image_sync_failed] Node [beast] is the source of the base image sync.')
            ->assertFailed();
    });
});

describe('task-vms:retire-host', function (): void {
    it('removes the host key from every peer and the host setup, only once its entry is gone', function (): void {
        $far = sync_node('far', '10.44.0.30', '192.168.6.50', $this->cluster);
        $this->fleet->host('10.44.0.30')->lan = '192.168.6.50';
        sync_hosts($this->beast, $this->shark, $far);
        ($this->link)();

        $this->artisan('task-vms:retire-host', ['node' => (string) $this->shark->id])
            ->expectsOutputToContain('[task_vm.host_configured]')
            ->assertFailed();

        sync_hosts($this->beast, $far);
        $this->artisan('task-vms:retire-host', ['node' => (string) $this->shark->id])->assertSuccessful();

        $shark = $this->fleet->host('10.44.0.11');
        foreach (['10.44.0.7', '10.44.0.30'] as $address) {
            expect(implode("\n", $this->fleet->host($address)->authorizedKeys))->not->toContain("orbit-task-image-{$this->shark->id}")
                ->and($this->fleet->host($address)->authorizedKeys)->toHaveCount(1);
        }
        expect($shark->authorizedKeys)->toBe([])
            ->and($shark->transferKey)->toBeNull()
            ->and($shark->receiver)->toBeNull()
            ->and($this->fleet->argv('10.44.0.11'))->toContain('rm -rf -- /etc/orbit/task-vms/image-transfer /usr/local/libexec/orbit/task-vm-image-receive /var/lib/orbit-task-vm-image');
    });

    it('cleans the peers of a host whose Node is gone', function (): void {
        ($this->link)();
        sync_hosts($this->beast);
        $id = $this->shark->id;
        $this->shark->delete();

        $sharkCommands = count($this->fleet->argv('10.44.0.11'));

        $this->artisan('task-vms:retire-host', ['node' => (string) $id])->assertSuccessful();

        expect($this->fleet->host('10.44.0.7')->authorizedKeys)->toBe([])
            ->and($this->fleet->argv('10.44.0.11'))->toHaveCount($sharkCommands);
    });

    it('forgets the key of one Node on every host and keeps the others', function (): void {
        $far = sync_node('far', '10.44.0.30', '192.168.6.50', $this->cluster);
        $this->fleet->host('10.44.0.30')->lan = '192.168.6.50';
        sync_hosts($this->beast, $this->shark, $far);
        ($this->link)();
        sync_hosts($this->beast, $far);

        app(TaskVmImagePeers::class)->forget($this->shark->id);

        foreach (['10.44.0.7', '10.44.0.30'] as $address) {
            expect(implode("\n", $this->fleet->host($address)->authorizedKeys))->not->toContain("orbit-task-image-{$this->shark->id}")
                ->and($this->fleet->host($address)->authorizedKeys)->toHaveCount(1);
        }
        expect($this->fleet->host('10.44.0.11')->authorizedKeys)->toHaveCount(2);
    });
});

describe('the nightly refresh', function (): void {
    it('queues the build on the first host without changing any alias yet', function (): void {
        Queue::fake();
        app()->call([new RefreshTaskVmBaseImages, 'handle']);
        Queue::assertPushed(LaunchImageBuilder::class, 1);
        Queue::assertNotPushed(RefreshTaskVmWorkspaceTemplates::class);
        expect(TaskVmImageBuild::query()->sole()->host_node_id)->toBe($this->beast->id)
            ->and($this->fleet->host('10.44.0.11')->images[SYNC_SHARK])->toContain(TaskVmHost::BaseImage);
    });

    it('does not run the launch or sync in the nightly dispatch job', function (): void {
        Queue::fake();
        $this->fleet->host('10.44.0.7')->fail = 'launch';
        app()->call([new RefreshTaskVmBaseImages, 'handle']);
        expect($this->fleet->host('10.44.0.11')->images)->toBe([SYNC_SHARK => ['orbit-task-base-20261010030500', TaskVmHost::BaseImage]])
            ->and(TaskVmImageBuild::query()->sole()->state)->toBe(TaskVmImageBuildState::Running);
        Queue::assertNotPushed(RefreshTaskVmWorkspaceTemplates::class);
    });

    it('changes nothing while the first host has no base image', function (): void {
        $this->fleet->host('10.44.0.7')->images = [SYNC_STOCK => [TaskVmHost::SourceImage]];
        $commands = count($this->fleet->commands);
        app()->call([new RefreshTaskVmBaseImages, 'handle']);
        expect(array_slice($this->fleet->argv('10.44.0.7'), -1))->toBe(['incus --project orbit-tasks image list --format json'])
            ->and(count($this->fleet->commands))->toBe($commands + 1);
    });
});
