<?php

declare(strict_types=1);

use App\Domain\Clusters\ClusterState;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\Firewall\NodeFirewallDesiredRules;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\IncusTaskVmImageSync;
use App\Infrastructure\TaskVms\TaskVmImagePeers;
use App\Jobs\TaskVms\RefreshTaskVmBaseImages;
use App\Models\Cluster;
use App\Models\Node;
use Illuminate\Support\Sleep;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\FakeIncusFleet;
use Tests\Support\FakeIncusFleetHost;

const SYNC_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeGatewayPublicKeyMaterial0123456789abcd orbit@gateway';

const SYNC_NEW = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

const SYNC_OLD = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

const SYNC_SHARK = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

const SYNC_STOCK = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';

/** Configures `$nodes` as task VM hosts, in order. */
function sync_hosts(Node ...$nodes): TaskVmSettings
{
    $settings = new TaskVmSettings(true, 4, '10.44.0.128/25', array_map(
        static fn (Node $node, int $index): TaskVmHost => new TaskVmHost($node->id, 'orbit-tasks', 'orbittask0', '10.251.'.(77 + $index).'.0/24', 2, 2, '4GiB', '20GiB'),
        $nodes,
        array_keys($nodes),
    ), 'http://10.44.0.3:8317', null, null, []);
    app()->instance(TaskVmSettings::class, $settings);

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
    it('lets hosts in one Cluster reach each other on the LAN with restricted certificates', function (): void {
        ($this->link)();

        $beast = $this->fleet->host('10.44.0.7');
        $shark = $this->fleet->host('10.44.0.11');
        $sharkTrust = array_values($beast->trust);
        expect($beast->httpsAddress)->toBe('192.168.6.20:8443')
            ->and($shark->httpsAddress)->toBe('192.168.6.40:8443')
            ->and(array_keys($beast->trust))->toBe([openssl_x509_fingerprint((string) $shark->clientCertificate, 'sha256')])
            ->and([$sharkTrust[0]['name'], $sharkTrust[0]['restricted'], $sharkTrust[0]['projects']])->toBe(["orbit-task-image-{$this->shark->id}", true, ['orbit-tasks']])
            ->and(Yaml::parse((string) $shark->remotes)['remotes']["node-{$this->beast->id}"])->toBe([
                'addr' => 'https://192.168.6.20:8443', 'auth_type' => 'tls', 'project' => 'orbit-tasks', 'protocol' => 'incus', 'public' => false,
            ])
            ->and($shark->serverCertificates)->toBe(["node-{$this->beast->id}" => $beast->serverCertificate()])
            ->and($beast->ufwRules)->toBe(["192.168.6.20 8443/tcp ALLOW IN 192.168.6.40 # orbit:task-vm-image:{$this->shark->id}"])
            ->and($shark->ufwRules)->toBe(["192.168.6.40 8443/tcp ALLOW IN 192.168.6.20 # orbit:task-vm-image:{$this->beast->id}"]);

        $commands = count($this->fleet->commands);
        ($this->link)();

        $again = array_map(static fn (array $command): string => implode(' ', $command[1]), array_slice($this->fleet->commands, $commands));
        expect(array_filter($again, static fn (string $command): bool => str_contains($command, 'config set') || str_contains($command, 'add-certificate')
            || str_contains($command, 'trust remove') || str_contains($command, 'ufw allow') || str_contains($command, 'ufw --force delete')))->toBe([]);
    });

    it('uses WireGuard on the interface orbit when a peer is in another Cluster', function (): void {
        $other = Cluster::query()->create(['name' => 'remote', 'state' => ClusterState::Active]);
        $far = sync_node('far', '10.44.0.30', '192.168.9.5', $other);
        sync_hosts($this->beast, $this->shark, $far);

        ($this->link)();

        expect($this->fleet->host('10.44.0.7')->httpsAddress)->toBe('10.44.0.7:8443')
            ->and($this->fleet->host('10.44.0.7')->ufwRules)->toBe([
                "10.44.0.7 8443/tcp on orbit ALLOW IN 10.44.0.11 # orbit:task-vm-image:{$this->shark->id}",
                "10.44.0.7 8443/tcp on orbit ALLOW IN 10.44.0.30 # orbit:task-vm-image:{$far->id}",
            ])
            ->and(Yaml::parse((string) $this->fleet->host('10.44.0.30')->remotes)['remotes']["node-{$this->shark->id}"]['addr'])->toBe('https://10.44.0.11:8443');
    });

    it('moves an address it set, and never takes over an address it did not set', function (): void {
        $this->fleet->host('10.44.0.7')->httpsAddress = '10.44.0.7:8443';
        $this->fleet->host('10.44.0.11')->httpsAddress = '0.0.0.0:8443';

        expect(fn () => ($this->link)())->toThrow(fn (TaskVmException $e) => expect([$e->errorCode, $e->getMessage()])
            ->toBe(['task_vm.incus_address_conflict', 'Incus on Node [shark] listens on [0.0.0.0:8443], not on an address of the Node with port 8443. Unset core.https_address or set it to [192.168.6.40:8443].']));
        expect($this->fleet->host('10.44.0.7')->httpsAddress)->toBe('192.168.6.20:8443')
            ->and($this->fleet->host('10.44.0.11')->httpsAddress)->toBe('0.0.0.0:8443');
    });

    it('replaces a peer certificate that changed and removes trust that no peer needs', function (): void {
        ($this->link)();
        $beast = $this->fleet->host('10.44.0.7');
        $stale = openssl_x509_fingerprint(sync_other_certificate(), 'sha256');
        $beast->trust[$stale] = ['name' => 'orbit-task-image-99', 'type' => 'client', 'restricted' => true, 'projects' => ['orbit-tasks'], 'certificate' => ''];
        $beast->trust['f'.str_repeat('0', 63)] = ['name' => 'operator-laptop', 'type' => 'client', 'restricted' => false, 'projects' => [], 'certificate' => ''];
        $this->fleet->host('10.44.0.11')->clientCertificate = null;

        ($this->link)();

        expect(array_column($beast->trust, 'name'))->toBe(['operator-laptop', "orbit-task-image-{$this->shark->id}"])
            ->and(array_keys($beast->trust)[1])->toBe(openssl_x509_fingerprint((string) $this->fleet->host('10.44.0.11')->clientCertificate, 'sha256'));
    });

    it('fails loudly when a peer rule cannot be added, and keeps the rules it had', function (): void {
        $this->fleet->host('10.44.0.11')->fail = 'ufw allow';

        expect(fn () => ($this->link)())->toThrow(fn (TaskVmException $e) => expect($e->errorCode)->toBe('node.firewall_convergence_failed')
            ->and($e->getMessage())->toContain('[shark]'));
        expect($this->fleet->host('10.44.0.11')->ufwRules)->toBe([]);
    });

    it('lists the peer rules among the rules Orbit intends on the host', function (): void {
        $rules = app(NodeFirewallDesiredRules::class)->forNode($this->beast);
        $peer = collect($rules)->firstWhere('name', "orbit:task-vm-image:{$this->shark->id}");

        expect($peer->role)->toBe('task-vms')
            ->and([$peer->shape->source, $peer->shape->destination, $peer->shape->port, $peer->shape->inInterface])->toBe(['192.168.6.40', '192.168.6.20', '8443', null])
            ->and(collect(app(NodeFirewallDesiredRules::class)->forNode(sync_node('other', '10.44.0.40', null, null)))->pluck('role'))->not->toContain('task-vms');
    });
});

describe('a sync', function (): void {
    it('copies the source image, checks its fingerprint, smoke-tests it, promotes it, and prunes the old one', function (): void {
        ($this->link)();
        $stages = [];

        $copied = app(IncusTaskVmImageSync::class)->sync($this->settings->host($this->beast->id), $this->beast, $this->settings->host($this->shark->id), $this->shark, SYNC_OLD, function (string $stage, float $seconds) use (&$stages): void {
            $stages[] = $stage;
        });

        $shark = $this->fleet->host('10.44.0.11');
        expect($copied)->toBeTrue()
            ->and($stages)->toBe(['inspect', 'sweep', 'copy', 'verify', 'smoke', 'promote', 'prune'])
            ->and($shark->images)->toBe([SYNC_OLD => ['orbit-task-base-20261010030000', TaskVmHost::BaseImage]])
            ->and($shark->instances)->toBe([])
            ->and($this->fleet->argv('10.44.0.11'))->toContain(
                "env INCUS_CONF=/etc/orbit/task-vms/incus incus image copy --quiet --project orbit-tasks --target-project orbit-tasks node-{$this->beast->id}:".SYNC_OLD.' local: --alias orbit-task-base-20261010030000',
                'incus --project orbit-tasks launch --vm --config limits.cpu=2 --config limits.memory=4GiB --device root,size=20GiB --device eth0,network=orbittask0 --device eth0,security.port_isolation=true -- orbit-task-base-20261010030000 tvm-image-20261011030000-sync-smoke',
            )
            ->and($this->fleet->host('10.44.0.7')->images)->toBe([SYNC_STOCK => [TaskVmHost::SourceImage], SYNC_OLD => ['orbit-task-base-20261010030000', TaskVmHost::BaseImage]]);

        expect(app(IncusTaskVmImageSync::class)->sync($this->settings->host($this->beast->id), $this->beast, $this->settings->host($this->shark->id), $this->shark, SYNC_OLD))->toBeFalse();
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
            ->and($this->fleet->argv('10.44.0.7'))->each->not->toMatch('/ (delete|launch|alias|copy|publish) /');
    })->with([
        'fingerprint mismatch' => [fn ($shark) => $shark->copyAs = 'f'.str_repeat('1', 63), 'verify', 'names the image [f111'],
        'smoke VM without a host key' => [fn ($shark) => $shark->fail = 'ssh-keygen', 'smoke', 'exec ssh-keygen'],
        'smoke VM that rebooted' => [fn ($shark) => $shark->boots = 2, 'smoke', 'it rebooted during its first boot'],
        'copy without trust' => [fn ($shark, $beast) => $beast->trust = [], 'copy', 'not authorized'],
        'copy from a source that does not listen' => [fn ($shark, $beast) => $beast->httpsAddress = '', 'copy', 'connection refused'],
        'pinned server certificate does not match' => [fn ($shark) => $shark->serverCertificates = array_map(static fn (): string => sync_other_certificate(), $shark->serverCertificates), 'copy', 'certificate signed by unknown authority'],
    ]);
});

describe('task-vms:build-image', function (): void {
    it('builds once on the named host and syncs the identical image to every other host', function (): void {
        ($this->link)();

        $this->artisan('task-vms:build-image', ['node' => 'beast'])
            ->expectsOutputToContain('Node [beast] has the base image [orbit-task-base] (aaaaaaaaaaaa)')
            ->expectsOutputToContain('Node [shark] has the base image after')
            ->assertSuccessful();

        expect($this->fleet->host('10.44.0.11')->images)->toBe([SYNC_NEW => ['orbit-task-base-20261011030000', TaskVmHost::BaseImage]])
            ->and($this->fleet->host('10.44.0.7')->images)->toBe([SYNC_STOCK => [TaskVmHost::SourceImage], SYNC_NEW => ['orbit-task-base-20261011030000', TaskVmHost::BaseImage]])
            ->and($this->fleet->argv('10.44.0.11'))->not->toContain('incus --project orbit-tasks publish --compression none --alias orbit-task-base-20261011030000 -- tvm-image-20261011030000');
    });

    it('reports each host, and one failed host neither stops the others nor touches the source', function (): void {
        $far = sync_node('far', '10.44.0.30', '192.168.6.50', $this->cluster);
        sync_hosts($this->beast, $this->shark, $far);
        $this->fleet->host('10.44.0.30')->images = [SYNC_SHARK => ['orbit-task-base-20261010030500', TaskVmHost::BaseImage]];
        ($this->link)();
        $this->fleet->host('10.44.0.11')->fail = 'image copy';

        $this->artisan('task-vms:build-image', ['node' => 'beast'])
            ->expectsOutputToContain('Node [shark] failed after 0.0s (inspect 0.0s, sweep 0.0s): [task_vm.image_sync_failed] The base image sync of Node [shark] from Node [beast] failed at stage [copy]')
            ->expectsOutputToContain('Node [far] has the base image after')
            ->assertFailed();

        expect($this->fleet->host('10.44.0.11')->images)->toBe([SYNC_SHARK => ['orbit-task-base-20261010030500', TaskVmHost::BaseImage]])
            ->and($this->fleet->host('10.44.0.30')->images)->toBe([SYNC_NEW => ['orbit-task-base-20261011030000', TaskVmHost::BaseImage]])
            ->and($this->fleet->host('10.44.0.7')->images[SYNC_NEW])->toContain(TaskVmHost::BaseImage);
    });
});

describe('task-vms:sync-image', function (): void {
    it('syncs a host from the first configured host, and then finds it current', function (): void {
        ($this->link)();

        $this->artisan('task-vms:sync-image', ['node' => 'shark'])
            ->expectsOutputToContain('copy')
            ->expectsOutputToContain('Node [shark] has the base image [orbit-task-base] (bbbbbbbbbbbb) of Node [beast] after')
            ->assertSuccessful();
        $this->artisan('task-vms:sync-image', ['node' => 'shark'])
            ->expectsOutputToContain('Node [shark] already has the base image [orbit-task-base] (bbbbbbbbbbbb) of Node [beast].')
            ->assertSuccessful();
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
    it('removes the host from every peer and its own setup, only once its entry is gone', function (): void {
        $far = sync_node('far', '10.44.0.30', '192.168.6.50', $this->cluster);
        sync_hosts($this->beast, $this->shark, $far);
        ($this->link)();

        $this->artisan('task-vms:retire-host', ['node' => (string) $this->shark->id])
            ->expectsOutputToContain('[task_vm.host_configured]')
            ->assertFailed();

        sync_hosts($this->beast, $far);
        $this->artisan('task-vms:retire-host', ['node' => (string) $this->shark->id])->assertSuccessful();

        $shark = $this->fleet->host('10.44.0.11');
        foreach (['10.44.0.7', '10.44.0.30'] as $address) {
            $peer = $this->fleet->host($address);
            expect(array_column($peer->trust, 'name'))->not->toContain("orbit-task-image-{$this->shark->id}")
                ->and(implode("\n", $peer->ufwRules))->not->toContain("orbit:task-vm-image:{$this->shark->id}")
                ->and(array_keys(Yaml::parse((string) $peer->remotes)['remotes']))->not->toContain("node-{$this->shark->id}");
        }
        expect($shark->httpsAddress)->toBe('')
            ->and($shark->trust)->toBe([])
            ->and($shark->ufwRules)->toBe([])
            ->and($shark->clientCertificate)->toBeNull()
            ->and($this->fleet->argv('10.44.0.11'))->toContain('rm -rf -- /etc/orbit/task-vms/incus');
    });

    it('cleans the peers of a host whose Node is gone', function (): void {
        ($this->link)();
        sync_hosts($this->beast);
        $id = $this->shark->id;
        $this->shark->delete();

        $sharkCommands = count($this->fleet->argv('10.44.0.11'));

        $this->artisan('task-vms:retire-host', ['node' => (string) $id])->assertSuccessful();

        $beast = $this->fleet->host('10.44.0.7');
        expect($beast->trust)->toBe([])
            ->and($beast->ufwRules)->toBe([])
            ->and($beast->httpsAddress)->toBe('')
            ->and($this->fleet->argv('10.44.0.11'))->toHaveCount($sharkCommands);
    });
});

describe('the nightly refresh', function (): void {
    it('builds once on the first host and syncs every other host', function (): void {
        ($this->link)();

        app()->call([new RefreshTaskVmBaseImages, 'handle']);

        expect($this->fleet->host('10.44.0.11')->images)->toBe([SYNC_NEW => ['orbit-task-base-20261011030000', TaskVmHost::BaseImage]])
            ->and($this->fleet->argv('10.44.0.11'))->not->toContain('incus --project orbit-tasks launch --vm --config limits.cpu=2 --config limits.memory=4GiB --device root,size=20GiB --device eth0,network=orbittask0 --device eth0,security.port_isolation=true -- ubuntu-26.04-vm tvm-image-20261011030000');
    });

    it('changes no host when the build fails', function (): void {
        ($this->link)();
        $this->fleet->host('10.44.0.7')->fail = 'launch';

        expect(fn () => app()->call([new RefreshTaskVmBaseImages, 'handle']))
            ->toThrow(TaskVmException::class, 'The base image build of Node [beast] failed at stage [launch]');
        expect($this->fleet->host('10.44.0.11')->images)->toBe([SYNC_SHARK => ['orbit-task-base-20261010030500', TaskVmHost::BaseImage]])
            ->and($this->fleet->argv('10.44.0.11'))->not->toContain('incus --project orbit-tasks image list --format json');
    });

    it('fails after the other hosts when a sync fails, and changes nothing while the first host has no base image', function (): void {
        ($this->link)();
        $this->fleet->host('10.44.0.11')->fail = 'ssh-keygen';

        expect(fn () => app()->call([new RefreshTaskVmBaseImages, 'handle']))
            ->toThrow(TaskVmException::class, 'The base image sync failed on Node [shark]: task_vm.image_sync_failed.');

        $this->fleet->host('10.44.0.7')->images = [SYNC_STOCK => [TaskVmHost::SourceImage]];
        $commands = count($this->fleet->commands);
        app()->call([new RefreshTaskVmBaseImages, 'handle']);
        expect(array_slice($this->fleet->argv('10.44.0.7'), -1))->toBe(['incus --project orbit-tasks image list --format json'])
            ->and(count($this->fleet->commands))->toBe($commands + 1);
    });
});

/** A certificate that no fake host uses. */
function sync_other_certificate(): string
{
    return FakeIncusFleetHost::certificateWithKey('other')[0];
}
