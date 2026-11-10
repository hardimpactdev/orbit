<?php

declare(strict_types=1);

use App\Actions\Nodes\AllocateInstanceBridgeAction;
use App\Actions\Nodes\UpdateNodeAction;
use App\Data\Nodes\NodeSettingsData;
use App\Data\Nodes\NodeTaskVmsData;
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

        expect(array_diff_key($intent, array_flip(['incus', 'image', 'folders'])))->toBe([
            'project' => 'orbit-instances', 'network' => 'orbitinst0', 'pool' => 'orbit-instances', 'cidr' => '10.253.0.0/24',
            'address' => '10.253.0.1/24', 'build_address' => '10.253.0.254', 'dhcp_range' => '10.253.0.2-10.253.0.253', 'stock' => 'ubuntu-26.04-instance-stock',
            'forwarder_port' => 8319, 'ids' => 201 * 65536,
            // `incus exec` sessions, which neither systemd nor a login shell starts, get it from the profile.
            'profile_config' => ['environment.VP_HOME' => '/opt/orbit-runtime/current/vite-plus-home', 'environment.VP_NO_UPDATE_CHECK' => '1'],
        ])
            ->and($intent['incus'])->toBe($recipe->incus('amd64'))
            ->and($intent['image'])->toBe(InstanceImage::for('10.253.0.1'))
            ->and($intent['folders'])->toBe(InstanceFolderPlan::for($recipe->instances));
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

    it('lists the bridge\'s DHCP and DNS input rules under their own names for a build-list Node with a bridge', function (): void {
        $node = instanceHostNode();
        expect(app(NodeFirewallRuleCatalog::class)->instanceHostInput($node))->toBe([]);
        config(['instances.container_nodes' => [$node->id]]);
        app(AllocateInstanceBridgeAction::class)->execute($node);

        $rules = app(NodeFirewallRuleCatalog::class)->instanceHostInput($node->refresh());

        expect(array_map(static fn ($rule): string => $rule->shape->comment, $rules))->toBe(NodeFirewallRuleCatalog::InstanceInputComments)
            ->and($rules[1]->arguments)->toBe(['sudo', 'ufw', 'allow', 'in', 'on', 'orbitinst0', 'proto', 'tcp', 'from', 'any', 'to', '10.253.0.1', 'port', '53', 'comment', 'orbit:instances-dns-tcp'])
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

    it('runs Vite+ from the folder\'s home with its update check off, for every unit and login shell', function (): void {
        $files = array_column(InstanceImage::for('10.253.0.1')['files'], null, 'path');

        expect($files['/etc/systemd/system.conf.d/90-orbit-runtime.conf']['content'])
            ->toBe("# Managed by Orbit.\n[Manager]\nDefaultEnvironment=VP_HOME=/opt/orbit-runtime/current/vite-plus-home VP_NO_UPDATE_CHECK=1\n")
            ->and($files['/etc/profile.d/90-orbit-runtime.sh']['content'])->toBe("# Managed by Orbit.\nexport VP_HOME=/opt/orbit-runtime/current/vite-plus-home VP_NO_UPDATE_CHECK=1\n")
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

        // `vp` is no program of its part: the folder links it from the Vite+ home.
        expect(array_keys($programs($plan['runtime'])))->toBe(['node', 'npm', 'npx', 'bun', 'composer', 'rg'])
            ->and(array_keys($programs($plan['agent'])))->toBe(['t3', 'claude', 'codex'])
            ->and(array_column($plan['runtime']['parts'], 'archive', 'name'))->toBe(['node' => 'tar', 'bun' => 'zip', 'composer' => 'file', 'ripgrep' => 'tar', 'vite-plus' => 'tar', 'php/8.5' => 'tar'])
            ->and(array_column($plan['agent']['parts'], 'archive', 'name'))->toBe(['t3' => 'tar', 'claude-code' => 'tar', 'codex' => 'tar']);
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
            ->and(InstanceNotes::all()[$note])->toContain('Composer', '`/opt/orbit-runtime/current/php/<version>/bin`', '`php` and `frankenphp`', 'No `php` is on `PATH`');
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
