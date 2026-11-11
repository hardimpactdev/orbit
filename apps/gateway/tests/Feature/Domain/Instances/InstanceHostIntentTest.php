<?php

declare(strict_types=1);

use App\Actions\Nodes\AllocateInstanceBridgeAction;
use App\Actions\Nodes\UpdateNodeAction;
use App\Data\Nodes\NodeSettingsData;
use App\Data\Nodes\NodeTaskVmsData;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceDevPorts;
use App\Domain\Instances\InstanceEgress;
use App\Domain\Instances\InstanceFolderPlan;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Instances\InstanceImage;
use App\Domain\Instances\InstanceNotes;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NativeNodeRoleRuntimeInput;
use App\Domain\Nodes\NodeRoleRuntimeInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Infrastructure\Firewall\NodeFirewallDesiredRules;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\MachineSoftware\ArtifactPin;
use App\Infrastructure\MachineSoftware\FolderPin;
use App\Infrastructure\MachineSoftware\InstanceFolders;
use App\Infrastructure\MachineSoftware\StaticPhpBuild;
use App\Infrastructure\MachineSoftware\VitePlusHome;
use App\Infrastructure\MachineSoftware\VitePlusRuntimeLock;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

function instanceHostNode(string $name = 'shark', string $ip = '10.44.0.11'): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => $ip, 'public_ssh_host' => $ip, 'user' => 'orbit',
    ]));
}

/** @return array<string, FolderPin> */
function instanceFolderPins(string $version = '1.0'): array
{
    return ['tool' => new FolderPin($version, new ArtifactPin("https://example.test/tool-{$version}.tar.gz", 'sha256', str_repeat('a', 64)), ['tool' => "tool-{$version}/tool"])];
}

describe('the container branch of the app-dev converge', function (): void {
    it('gives a Node that is not on the build list nothing and leaves its bridge unallocated', function (): void {
        $node = instanceHostNode();

        expect(app(InstanceHostIntent::class)->for($node))->toBeNull()
            ->and($node->refresh()->instance_bridge)->toBeNull();
    });

    it('gives a task VM nothing, even on the build list', function (): void {
        $node = instanceHostNode();
        config(['instances.container_nodes' => [$node->id]]);
        TaskVm::query()->create(['node_id' => $node->id, 'provider' => 'incus', 'name' => 'tvm-1', 'wireguard_ip' => '10.44.9.1', 'state' => 'ready']);

        expect(app(InstanceHostIntent::class)->for($node))->toBeNull()
            ->and($node->refresh()->instance_bridge)->toBeNull();
    });

    it('reads the bridge only: a build-list Node without one is refused, and only the admission allocates it', function (): void {
        $node = instanceHostNode();
        config(['instances.container_nodes' => [$node->id]]);

        expect(fn () => app(InstanceHostIntent::class)->for($node))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.host_unavailable'))
            ->and($node->refresh()->instance_bridge)->toBeNull();

        $assignment = $node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
        app(NodeRoleRuntimeInput::class)->prepare($node, $assignment);

        expect($node->refresh()->instance_bridge)->toBe('10.253.0.0/24');
    });

    it('names the project, pool, image and folders of a prepared Node', function (): void {
        $node = instanceHostNode();
        config(['instances.container_nodes' => [$node->id]]);
        $recipe = app(RecipeImageBuilds::class)->currentRecipe();
        app(InstanceHostIntent::class)->prepare($node);

        $intent = app(InstanceHostIntent::class)->for($node);

        expect(array_diff_key($intent, array_flip(['incus', 'image', 'folders', 'egress', 'ingress', 'profile_config', 'dev_ports'])))->toBe([
            'project' => 'orbit-instances', 'network' => 'orbitinst0', 'pool' => 'orbit-instances', 'cidr' => '10.253.0.0/24',
            'address' => '10.253.0.1/24', 'build_address' => '10.253.0.254', 'dhcp_range' => '10.253.0.2-10.253.0.253', 'stock' => 'ubuntu-26.04-instance-stock',
            'forwarder_port' => 8319, 'bridge_endpoints' => [], 'ids' => 201 * 65536, 'arc' => true,
        ])
            ->and($intent['incus'])->toBe($recipe->incus('amd64'))
            ->and($intent['image'])->toBe(InstanceImage::for('10.253.0.1'))
            ->and($intent['folders'])->toBe(InstanceFolderPlan::for($recipe->instances))
            ->and($intent['egress'])->toBe(InstanceEgress::for('orbitinst0', '10.253.0.1', '10.253.0.0/24', app(PeerDnsPolicy::class)->domains()))
            // `incus exec` sessions, which neither systemd nor a login shell starts, get the container environment from the profile.
            ->and($intent['profile_config'])->toMatchArray(['environment.PATH' => '/opt/orbit-runtime/current/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
                'environment.VP_HOME' => '/var/lib/orbit/vite-plus-home', 'environment.VP_NO_UPDATE_CHECK' => '1',
                'environment.HTTPS_PROXY' => 'http://10.253.0.1:3128', 'environment.no_proxy' => 'localhost,127.0.0.1,::1,10.253.0.1'])
            ->and($intent['profile_config'])->toHaveCount(count(InstanceImage::environment('10.253.0.1')) + 1);
    });

    it('lets the bridge ACL admit what a container\'s ACL may admit: the Node to FrankenPHP and the development ports, and T3\'s clients', function (): void {
        $node = instanceHostNode();
        config(['instances.container_nodes' => [$node->id]]);
        app(InstanceHostIntent::class)->prepare($node);

        $ingress = app(InstanceHostIntent::class)->for($node)['ingress'];

        // Incus rejects everything else that the Node sends or forwards to the bridge, before a container's own ACL sees it.
        expect($ingress)->toBe([
            ['action' => 'allow', 'source' => '10.253.0.1/32', 'protocol' => 'tcp', 'destination_port' => '1024-65535', 'state' => 'enabled'],
            ['action' => 'allow', 'protocol' => 'tcp', 'destination_port' => '3773', 'state' => 'enabled'],
        ])
            // One range rule is enough only while FrankenPHP's port lies in the range of the development ports.
            ->and(InstanceContainer::WebPort)->toBeGreaterThanOrEqual(InstanceDevPorts::First)->toBeLessThanOrEqual(InstanceDevPorts::Last)
            ->and($ingress[0]['destination_port'])->toBe(InstanceDevPorts::First.'-'.InstanceDevPorts::Last)
            ->and($ingress[1]['destination_port'])->toBe((string) InstanceHostIntent::T3Port);
    });

    it('needs the ID ranges of a host\'s task containers too, which share root\'s one range', function (): void {
        $node = Node::withoutEvents(fn (): Node => Node::query()->create([
            'name' => 'beast', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.7',
            'public_ssh_host' => '10.44.0.7', 'user' => 'orbit', 'settings' => ['task_vms' => NodeTaskVmsData::validated(
                ['cidr' => '10.252.10.0/24', 'task_places' => 2, 'ci_places' => 2, 'containers' => ['cidr' => '10.252.11.0/24', 'task_places' => 2, 'ci_places' => 1]],
                Ipv4Subnet::from('10.44.0.0/24'))->toArray()],
        ]));
        config(['instances.container_nodes' => [$node->id]]);
        app(InstanceHostIntent::class)->prepare($node);

        expect(app(InstanceHostIntent::class)->for($node)['ids'])->toBe(201 * 65536 + 4 * 101000000);
    });

    it('prepares a task VM host beside its task side: its own bridge, and the task side keeps the ARC', function (): void {
        $node = Node::withoutEvents(fn (): Node => Node::query()->create([
            'name' => 'beast', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.7',
            'public_ssh_host' => '10.44.0.7', 'user' => 'orbit', 'settings' => ['task_vms' => NodeTaskVmsData::validated(
                ['cidr' => '10.253.0.0/24', 'task_places' => 4, 'ci_places' => 2], Ipv4Subnet::from('10.44.0.0/24'))->toArray()],
        ]));
        config(['instances.container_nodes' => [$node->id]]);
        app(InstanceHostIntent::class)->prepare($node);

        $intent = app(InstanceHostIntent::class)->for($node);

        // The task bridge holds the first /24 of the range, so the Instance bridge takes the next one.
        expect($intent)->toMatchArray(['project' => 'orbit-instances', 'network' => 'orbitinst0', 'pool' => 'orbit-instances',
            'cidr' => '10.253.1.0/24', 'ids' => 201 * 65536, 'arc' => false]);
    });

    it('adds the branch to the app-dev runtime of a build-list Node only, so every other intent stays exactly as it was', function (): void {
        $listed = instanceHostNode();
        $other = instanceHostNode('beast', '10.44.0.7');
        $capture = function (Node $node, RoleName $role): array {
            $assignment = $node->roles()->firstOrCreate(['role' => $role], ['status' => 'active']);

            return app(NativeNodeRoleRuntimeInput::class)->capture($node, $assignment, [], new Operation)['intent'];
        };
        $before = ['other' => $capture($other, RoleName::AppDev), 'ci' => $capture($listed, RoleName::CiRunner), 'app' => $capture($listed, RoleName::AppDev)];
        config(['instances.container_nodes' => [$listed->id]]);
        app(InstanceHostIntent::class)->prepare($listed);

        $after = $capture($listed, RoleName::AppDev);
        expect($capture($other, RoleName::AppDev))->toBe($before['other'])
            ->and($capture($listed, RoleName::CiRunner))->toBe($before['ci'])
            ->and(array_diff_key($after, ['instances' => true]))->toBe($before['app'])
            ->and($after['instances']['cidr'])->toBe('10.253.0.0/24')
            ->and($other->refresh()->instance_bridge)->toBeNull();
    });

    it('lists the bridge\'s DHCP and egress proxy input rules, and no DNS, for a build-list Node with a bridge', function (): void {
        $node = instanceHostNode();
        expect(app(NodeFirewallRuleCatalog::class)->instanceHostInput($node))->toBe([]);
        config(['instances.container_nodes' => [$node->id]]);
        app(AllocateInstanceBridgeAction::class)->execute($node);

        $rules = app(NodeFirewallRuleCatalog::class)->instanceHostInput($node->refresh());

        expect(array_map(static fn ($rule): string => $rule->shape->comment, $rules))->toBe(NodeFirewallRuleCatalog::InstanceInputComments)
            ->and($rules[0]->arguments)->toBe(['sudo', 'ufw', 'allow', 'in', 'on', 'orbitinst0', 'proto', 'udp', 'from', 'any', 'port', '68', 'to', 'any', 'port', '67', 'comment', 'orbit:instances-dhcp'])
            ->and($rules[1]->arguments)->toBe(['sudo', 'ufw', 'allow', 'in', 'on', 'orbitinst0', 'proto', 'tcp', 'from', 'any', 'to', '10.253.0.1', 'port', '3128', 'comment', 'orbit:instances-egress'])
            ->and(array_filter($rules, static fn ($rule): bool => $rule->shape->port === '53'))->toBe([])
            ->and(array_map(static fn ($row): string => $row->shape->comment, app(NodeFirewallDesiredRules::class)->forNode($node)))
            ->toContain(...NodeFirewallRuleCatalog::InstanceInputComments);
    });
});

describe('the Instance image', function (): void {
    it('lets only agent reach the model forwarder and only root and agent reach T3', function (): void {
        expect(InstanceImage::firewall('10.253.0.1'))->toContain(
            'ip daddr 10.253.0.1 tcp dport 8319 meta skuid != 2750 reject with tcp reset',
            'tcp dport 3773 meta skuid != { 0, 2750 } reject with tcp reset',
        );
    });

    it('runs T3 as agent from the agent folder, only in a named Instance, with the Claude updater off', function (): void {
        $unit = InstanceImage::t3Unit();

        expect($unit)->toContain(
            "User=agent\n", "UMask=0007\n", "Requires=nftables.service\n", "ConditionPathExists=/etc/orbit/instance\n",
            "Environment=PATH=/opt/orbit-agent/current/agent/bin:/opt/orbit-runtime/current/bin:/usr/local/bin:/usr/bin:/bin\n",
            "Environment=DISABLE_AUTOUPDATER=1\n", 'ExecStart=/opt/orbit-agent/current/agent/bin/t3 serve --host=0.0.0.0 --port=3773 --no-browser',
        );
    });

    it('puts the runtime folder and /usr/local/bin, where the Project\'s php is, on the PATH of every unit and login shell, runs Vite+ from its writable home', function (): void {
        $files = array_column(InstanceImage::for('10.253.0.1')['files'], null, 'path');
        // `composer` is in the runtime folder's bin; the container phase links the Project's `php` into /usr/local/bin.
        $path = '/opt/orbit-runtime/current/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

        expect($files['/etc/systemd/system.conf.d/90-orbit-runtime.conf']['content'])
            ->toStartWith("# Managed by Orbit.\n[Manager]\nDefaultEnvironment=PATH={$path} VP_HOME=/var/lib/orbit/vite-plus-home VP_NO_UPDATE_CHECK=1 ")
            // A login shell keeps what it inherits, such as the agent folder from T3's unit.
            ->and($files['/etc/profile.d/90-orbit-runtime.sh']['content'])
            ->toStartWith("# Managed by Orbit.\nexport PATH=/opt/orbit-runtime/current/bin:/usr/local/bin:\$PATH VP_HOME=/var/lib/orbit/vite-plus-home VP_NO_UPDATE_CHECK=1 ")
            ->and($files['/etc/profile.d/90-orbit-runtime.sh']['mode'])->toBe('0644');
    });

    it('installs each published static PHP build as php/<version>, checked against its extensions, and no unpublished one', function (): void {
        $recipe = app(RecipeImageBuilds::class)->currentRecipe()->instances;
        $build = $recipe->php['8.5'];
        $published = new StaticPhpBuild('8.5', $build->phpVersion, $build->frankenphp, $build->extensions, $build->build, $build->url,
            new ArtifactPin($build->url, 'sha256', str_repeat('d', 64), $build->phpVersion));
        $pending = new StaticPhpBuild('8.5', $build->phpVersion, $build->frankenphp, $build->extensions, $build->build, $build->url, null);
        $part = static fn (StaticPhpBuild $php): array => array_values(array_filter(
            InstanceFolderPlan::for(new InstanceFolders($recipe->runtime, $recipe->agent, ['8.5' => $php], $recipe->vitePlus))['runtime']['parts'],
            static fn (array $part): bool => str_starts_with($part['name'], 'php/'),
        ));

        expect($part($published))->toBe([['name' => 'php/8.5', 'version' => $build->phpVersion, 'url' => $build->url, 'archive' => 'tar',
            'sha256' => str_repeat('d', 64), 'bin' => [], 'static_php' => ['php_version' => $build->phpVersion, 'extensions' => $build->extensions]]])
            ->and($part($pending))->toBe([]);
    });

    it('sets up the users, the workspace and no sudo, and turns the Codex update check off', function (): void {
        $image = InstanceImage::for('10.253.0.1');
        $files = array_column($image['files'], null, 'path');

        expect($image['prepare'])->toContain(
            "touch /etc/cloud/cloud-init.disabled\n", 'useradd --uid 2750 --gid agent', 'useradd --uid 1000 --gid orbit', 'groupadd --gid 2760 work', 'passwd --lock agent',
            "find /etc/sudoers.d -mindepth 1 ! -name README -delete\n", 'install -d -o agent -g work -m 2770 /srv/workspace',
            'd:u::rwX,d:g::rwX,d:g:work:rwX,d:o::---', 'hidepid=invisible',
        )
            ->and($image['packages'])->toBe(['acl', 'git', 'nftables'])
            ->and($files['/etc/systemd/system/t3.service']['mode'])->toBe('0640')
            ->and($files['/home/agent/.codex/config.toml'])->toMatchArray(['content' => "check_for_update_on_startup = false\n", 'uid' => 2750, 'mode' => '0600'])
            ->and($files['/home/orbit/.gitconfig']['content'])->toBe("[safe]\n\tdirectory = /srv/workspace\n");
    });

    it('gives Vite+ a shared writable home for package managers while linking the pinned runtime', function (): void {
        $script = InstanceImage::for('10.253.0.1')['prepare'];
        $home = InstanceImage::RuntimeEnvironment['VP_HOME'];

        expect($script)->toContain(
            "install -d -o agent -g work -m 2770 {$home}\n",
            "d:g:work:rwX,d:o::--- {$home}\n",
            "install -d -o agent -g work -m 2770 {$home}/cache\n",
            'for part in bin js_runtime node_modules recipe config.json; do',
            'ln -s /opt/orbit-runtime/current/vite-plus-home/$part '.$home.'/$part',
        );
    });

    it('removes cloud-init\'s group lxd, which holds the gid of orbit, before it makes the groups', function (): void {
        $script = InstanceImage::for('10.253.0.1')['prepare'];

        // `groupadd --gid 1000` refuses a taken gid: on the stock image `lxd` took 1000 at first boot, and `ubuntu` got gid 1001.
        expect($script)->toContain("if getent group lxd >/dev/null 2>&1; then groupdel lxd; fi\n")
            // The user goes first: `groupdel` refuses a group that is still a user's.
            ->and(strpos($script, 'userdel --remove ubuntu'))->toBeLessThan(strpos($script, 'groupdel lxd'))
            ->and(strpos($script, 'groupdel lxd'))->toBeLessThan(strpos($script, 'groupadd --gid 1000 orbit'));
    });

    it('publishes the image with an empty machine-id, so each container writes its own at its first start', function (): void {
        $finish = InstanceImage::for('10.253.0.1')['finish'];

        // The file stays: a missing file would make the first start a systemd first boot, which resets the units to their presets.
        expect($finish)->toContain(['truncate', '--size', '0', '/etc/machine-id'])
            ->and(array_filter($finish, static fn (array $command): bool => in_array('/etc/machine-id', $command, true) && in_array($command[0], ['rm', 'unlink'], true)))->toBe([])
            ->and(array_search(['truncate', '--size', '0', '/etc/machine-id'], $finish, true))
            ->toBeGreaterThan(array_search(['systemctl', 'enable', 'nftables.service', 't3.service'], $finish, true));
    });

    it('gives every unit, login shell and apt the egress proxy, and takes no DNS server', function (): void {
        $files = array_column(InstanceImage::for('10.253.0.1')['files'], 'content', 'path');

        expect($files['/etc/systemd/system.conf.d/90-orbit-runtime.conf'])->toContain(
            ' HTTPS_PROXY=http://10.253.0.1:3128 ', ' https_proxy=http://10.253.0.1:3128 ', ' NO_PROXY=localhost,127.0.0.1,::1,10.253.0.1 ', " NODE_USE_ENV_PROXY=1\n",
        )
            ->and($files['/etc/profile.d/90-orbit-runtime.sh'])->toContain(' HTTP_PROXY=http://10.253.0.1:3128 ', ' no_proxy=localhost,127.0.0.1,::1,10.253.0.1 ')
            ->and($files['/etc/apt/apt.conf.d/90orbit-egress'])->toContain("Acquire::https::Proxy \"http://10.253.0.1:3128\";\n")
            ->and($files['/etc/netplan/90-orbit-no-dns.yaml'])->toContain("use-dns: false\n")
            ->and($files['/etc/systemd/resolved.conf.d/90-orbit-no-dns.conf'])->toContain("DNS=\nFallbackDNS=\n");
    });

    it('names each definition by its digest, so a change builds a new image', function (): void {
        $alias = InstanceImage::for('10.253.0.1')['alias'];

        expect($alias)->toMatch('/^orbit-instance-[0-9a-f]{12}$/')
            ->and(InstanceImage::for('10.253.0.1')['alias'])->toBe($alias)
            ->and(InstanceImage::for('10.253.1.1')['alias'])->not->toBe($alias);
    });
});

describe('the shared folders', function (): void {
    it('versions a folder by its whole content, notes included', function (): void {
        $plan = InstanceFolderPlan::for(new InstanceFolders(instanceFolderPins(), instanceFolderPins()));

        expect($plan['runtime']['version'])->toMatch('/^[0-9a-f]{12}$/')
            ->and($plan['runtime']['path'])->toBe('/opt/orbit-runtime')
            ->and($plan['agent']['path'])->toBe('/opt/orbit-agent')
            ->and($plan['runtime']['parts'])->toBe([['name' => 'tool', 'version' => '1.0', 'url' => 'https://example.test/tool-1.0.tar.gz',
                'archive' => 'tar', 'sha256' => str_repeat('a', 64), 'bin' => ['tool' => 'tool-1.0/tool']]])
            ->and($plan['agent']['notes'])->toBe(InstanceNotes::all())
            // The agent folder holds the notes too, so the same pins give it another version.
            ->and($plan['agent']['version'])->not->toBe($plan['runtime']['version'])
            ->and(InstanceFolderPlan::for(new InstanceFolders(instanceFolderPins('1.1'), instanceFolderPins()))['runtime']['version'])
            ->not->toBe($plan['runtime']['version'])
            ->and(InstanceFolderPlan::for(new InstanceFolders(instanceFolderPins('1.1'), instanceFolderPins()))['agent']['version'])
            ->toBe($plan['agent']['version']);
    });

    it('installs the recipe\'s runtime and agent programs from their pinned artifacts', function (): void {
        $plan = InstanceFolderPlan::for(app(RecipeImageBuilds::class)->currentRecipe()->instances);
        $programs = static fn (array $folder): array => array_merge(...array_map(static fn (array $part): array => $part['bin'], $folder['parts']));

        // `vp` is no program of its part: the folder links it from the Vite+ home. PHP Data Bridge has no program: PHP reads its files.
        expect(array_keys($programs($plan['runtime'])))->toBe(['node', 'npm', 'npx', 'bun', 'deno', 'composer', 'rg'])
            ->and(array_keys($programs($plan['agent'])))->toBe(['t3', 'claude', 'codex'])
            ->and(array_column($plan['runtime']['parts'], 'archive', 'name'))->toBe(['node' => 'tar', 'bun' => 'zip', 'deno' => 'zip', 'composer' => 'file', 'ripgrep' => 'tar', 'php-data-bridge' => 'tar', 'vite-plus' => 'tar', 'php/8.5' => 'tar'])
            ->and(array_column($plan['agent']['parts'], 'archive', 'name'))->toBe(['t3' => 'tar', 'claude-code' => 'tar', 'codex' => 'tar'])
            ->and(array_column($plan['runtime']['parts'], 'bin', 'name')['php-data-bridge'])->toBe([]);
    });

    it('builds one Vite+ home from the recipe\'s vp release, runtime lock and Node, at a path named by what goes into it', function (): void {
        $recipe = app(RecipeImageBuilds::class)->currentRecipe();
        $plan = InstanceFolderPlan::for($recipe->instances)['runtime'];
        $vitePlus = $plan['vite_plus'];

        expect(array_diff_key($vitePlus, ['home' => true, 'lock' => true]))->toBe([
            'part' => 'vite-plus', 'binary' => 'vp', 'version' => $recipe->tools['vp']->version, 'node' => $recipe->tools['node']->version,
        ])
            ->and($vitePlus['home'])->toMatch('~^/opt/orbit-runtime/vite-plus/[0-9a-f]{12}$~')
            ->and($vitePlus['lock'])->toBe(VitePlusRuntimeLock::read($recipe->tools['vp']))
            ->and(array_column($plan['parts'], 'bin', 'name')['vite-plus'])->toBe([]);

        // Another composer leaves the home alone; another Node builds a new one.
        $composer = $recipe->instances->runtime['composer'];
        $runtime = [...$recipe->instances->runtime, 'composer' => new FolderPin('9.9.9', new ArtifactPin('https://example.test/9.9.9/composer.phar', 'sha256', str_repeat('e', 64)), $composer->bin)];
        $node = $recipe->instances->vitePlus;
        $otherNode = new VitePlusHome($node->part, $node->binary, $node->vp, '24.99.0');
        $home = static fn (array $runtime, VitePlusHome $vitePlus): string => InstanceFolderPlan::for(new InstanceFolders($runtime, $recipe->instances->agent, [], $vitePlus))['runtime']['vite_plus']['home'];

        expect($home($runtime, $node))->toBe($vitePlus['home'])
            ->and($home($recipe->instances->runtime, $otherNode))->not->toBe($vitePlus['home']);
    });

    it('links each kind of Project to its environment note', function (ProjectType $type, string $note): void {
        expect(InstanceNotes::for($type))->toBe($note)
            ->and(InstanceNotes::all())->toHaveKey($note);
    })->with([
        [ProjectType::LaravelApp, 'web-app'], [ProjectType::SymfonyApp, 'web-app'], [ProjectType::Monorepo, 'monorepo'],
        [ProjectType::LaravelPackage, 'package'], [ProjectType::NodePackage, 'package'],
    ]);

    it('states facts only: the users, the workspace, the Instance file, no sudo and no internet', function (string $note): void {
        expect(InstanceNotes::all()[$note])->toContain('`agent`', '`orbit`', '`/srv/workspace`', '`/etc/orbit/instance`', 'There is no sudo.', 'no direct internet')
            // The pinned static PHP build and Composer are in the runtime folder, and the note says where.
            ->and(InstanceNotes::all()[$note])->toContain('Composer', '`/opt/orbit-runtime/current/php/<version>/bin`', '`php` and `frankenphp`', '`php` on `PATH`', 'so `composer` works')
            ->and(InstanceNotes::all()[$note])->not->toContain('No `php` is on `PATH`');
    })->with(['web-app', 'monorepo', 'package']);
});

describe('the bridge check and write', function (): void {
    /** @return list<array{sql: string, level: int}> */
    function bridgeQueries(Closure $run): array
    {
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = ['sql' => $query->sql, 'level' => DB::transactionLevel()];
        });
        $run();

        return $queries;
    }

    it('begins every transaction IMMEDIATE, so a transaction that reads the bridges holds the writer lock from its start', function (): void {
        expect(config('database.connections.'.config('database.default').'.transaction_mode'))->toBe('IMMEDIATE');
    });

    it('allocates a bridge in one transaction with the read of the recorded bridges', function (): void {
        $node = instanceHostNode();
        $base = DB::transactionLevel();

        $queries = bridgeQueries(fn () => app(AllocateInstanceBridgeAction::class)->execute($node));
        $read = array_find(array_reverse($queries), static fn (array $q): bool => str_contains($q['sql'], 'select "id", "settings", "instance_bridge" from "nodes"'));
        $write = array_find($queries, static fn (array $q): bool => str_starts_with($q['sql'], 'update "nodes" set "instance_bridge"'));

        expect($read['level'])->toBeGreaterThan($base)->and($write['level'])->toBe($read['level']);
    });

    it('checks and stores a task host\'s bridges during provisioning in one transaction, after the SSH work', function (): void {
        $node = instanceHostNode();
        // The roots are inspected over SSH before the transaction; this fixture has no Node to reach.
        app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver
        {
            public function resolve(Node $node): ManagedUserAccount
            {
                return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
            }
        });
        $settings = new NodeSettingsData(task_vms: NodeTaskVmsData::validated(['cidr' => '10.252.10.0/24', 'task_places' => 1, 'ci_places' => 0], Ipv4Subnet::from('10.44.0.0/24')));

        $base = DB::transactionLevel();
        $queries = bridgeQueries(fn () => app(UpdateNodeAction::class)->persistDuringProvisioning($node, $settings));
        $write = array_find_key($queries, static fn (array $q): bool => str_starts_with($q['sql'], 'update "nodes" set "settings"'));
        $reads = array_keys(array_filter($queries, static fn (array $q): bool => str_contains($q['sql'], 'select "id", "settings", "instance_bridge" from "nodes"')));
        $lastRead = max(array_filter($reads, static fn (int $index): bool => $index < $write));

        // The last bridge check before the write runs in the write's own transaction; before this change the write had none.
        expect($queries[$write]['level'])->toBeGreaterThan($base)
            ->and($queries[$lastRead]['level'])->toBe($queries[$write]['level'])
            ->and(array_filter(array_slice($queries, $lastRead, $write - $lastRead), static fn (array $q): bool => $q['level'] <= $base))->toBe([])
            ->and($node->refresh()->settings['task_vms']['cidr'])->toBe('10.252.10.0/24');
    });

});
