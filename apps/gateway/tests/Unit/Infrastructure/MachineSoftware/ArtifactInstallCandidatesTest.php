<?php

declare(strict_types=1);

use App\Domain\Fleet\NodeFootprint;
use App\Domain\Fleet\NodeFootprintArtifact;
use App\Domain\Nodes\CaddyRelease;
use App\Domain\Nodes\NativeCaddyPackageInput;
use App\Domain\Nodes\NativeNodeRoleAddInput;
use App\Domain\Nodes\NodeConvergeInput;
use App\Domain\Routes\NativeClusterRouterIntent;
use App\Domain\Routes\NativeRouteCertificateInput;
use App\Domain\Tools\ToolManagerIntent;
use App\Domain\Tools\ToolManagerName;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\MachineSoftware\PhpBuildInstaller;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Nodes\AgentCaddyPackage;
use App\Infrastructure\Nodes\Roles\RolePrerequisites;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('admits mirror then upstream candidates for Composer Debian prerequisites', function (): void {
    $recipe = new RecipeLoader()->load();
    $input = app(ToolManagerIntent::class)->nativeInput(new Node(['platform' => 'linux', 'user' => 'orbit']), ToolManagerName::Composer);

    foreach ($input['apt']['packages'] as $package) {
        if ($package['artifact'] === null) {
            continue;
        }
        $artifact = $recipe->tools[$package['name']]->artifacts['amd64'];
        expect($package['artifact']['urls'])->toBe([
            $recipe->artifactMirror.'/'.$package['name'].'_'.$artifact->digest.'.deb',
            $artifact->location,
        ])->and($package['artifact'])->not->toHaveKey('url');
    }
});

it('keeps the single upstream candidate for JavaScript archives', function (): void {
    $recipe = new RecipeLoader()->load();
    $input = app(ToolManagerIntent::class)->nativeInput(new Node(['platform' => 'linux', 'user' => 'orbit']), ToolManagerName::Vp);

    foreach ($input['input']['tools'] as $name => $pin) {
        expect($pin['urls'])->toBe([$recipe->tools[$name]->artifacts['amd64']->location])
            ->and($pin)->not->toHaveKey('url');
    }
});

it('projects mirror then upstream candidates for every role PHP package', function (): void {
    $recipe = new RecipeLoader()->load();
    $build = PhpBuildInstaller::input($recipe, ['8.5'], true, extras: 'keep', fpm: 'all', opentelemetry: true);
    $projection = new ReflectionMethod(NativeNodeRoleAddInput::class, 'phpPackages');
    $packages = $projection->invoke(new NativeNodeRoleAddInput, $build);

    expect($packages)->not->toBeEmpty();
    foreach ($packages as $package) {
        $artifact = $recipe->tools[$package['name']]->artifacts['amd64'];
        expect($package['urls'])->toBe([
            $recipe->artifactMirror.'/'.$package['name'].'_'.$artifact->digest.'.deb',
            $artifact->location,
        ])->and($package['sha256'])->toBe($artifact->digest)
            ->and($package)->not->toHaveKey('url');
    }
});

it('captures native Caddy candidates in the convergence footprint and role projection', function (): void {
    Schema::create('node_footprints', function (Blueprint $table): void {
        $table->integer('node_id');
    });
    // The SSH identity of the run names the Node that holds the swamp role.
    Schema::create('node_roles', function (Blueprint $table): void {
        $table->integer('node_id');
        $table->string('role');
        $table->string('status');
    });
    DB::table('node_roles')->insert(['node_id' => 12, 'role' => 'swamp', 'status' => 'active']);
    $delivery = app(AgentDelivery::class);
    $artifact = Mockery::mock(NodeFootprintArtifact::class);
    $artifact->shouldReceive('name')->andReturn('caddy-package');
    $artifact->shouldReceive('applies')->andReturn(true);
    $artifact->shouldReceive('digest')->andReturn('caddy');
    $footprint = new NodeFootprint($delivery, [$artifact]);
    $producer = new NodeConvergeInput($footprint, $delivery, [$artifact]);
    $node = (new Node(['user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64']))->forceFill(['id' => 12]);
    $snapshot = $producer->capture($node, false, false);
    $roles = $producer->roles($node, $snapshot['payloads']);

    expect($snapshot['payloads']['caddy-package'])->toBe(NativeCaddyPackageInput::make())
        ->and($roles['caddy_package'])->toBe(NativeCaddyPackageInput::make());
});

it('captures native Caddy candidates when admitting a Cluster Router', function (): void {
    Schema::create('nodes', function (Blueprint $table): void {
        $table->integer('id')->primary();
        foreach (['user', 'platform', 'architecture', 'status', 'wireguard_ip', 'ssh_host_key_type', 'ssh_host_key'] as $column) {
            $table->string($column);
        }
    });
    Schema::create('node_roles', function (Blueprint $table): void {
        foreach (['id', 'node_id', 'cluster_id'] as $column) {
            $table->integer($column);
        }
        $table->string('role');
        $table->string('status');
    });
    Schema::create('routes', function (Blueprint $table): void {
        $table->integer('id');
        $table->integer('cluster_id');
    });
    DB::table('nodes')->insert(['id' => 12, 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64',
        'status' => 'active', 'wireguard_ip' => '10.44.0.12', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'test-key']);
    DB::table('node_roles')->insert(['id' => 7, 'node_id' => 12, 'cluster_id' => 3,
        'role' => 'router', 'status' => 'provisioning']);
    DB::table('node_roles')->insert(['id' => 8, 'node_id' => 12, 'cluster_id' => 3, 'role' => 'swamp', 'status' => 'active']);
    $delivery = app(AgentDelivery::class);
    $producer = new NativeClusterRouterIntent($delivery,
        app(RolePrerequisites::class), app(NativeRouteCertificateInput::class));
    $operation = (new Operation)->forceFill(['kind' => 'cluster.router_set', 'node_id' => 12,
        'input' => ['cluster_id' => 3, 'candidate_role_id' => 7]]);

    expect($producer->forOperation($operation)['candidate']['caddy_package'])->toBe(NativeCaddyPackageInput::make());
});

it('admits native Caddy candidates without changing the frozen agent input', function (): void {
    $recipe = new RecipeLoader()->load();
    $artifact = $recipe->tools['caddy']->artifacts['amd64'];
    $candidates = [$recipe->artifactMirror.'/caddy_'.$artifact->digest.'.deb', $artifact->location];
    expect(NativeCaddyPackageInput::make()['urls'])->toBe($candidates)
        ->and(NativeCaddyPackageInput::make())->not->toHaveKey('url');

    $agentInput = [
        'release' => AgentCaddyPackage::RELEASE,
        'amd64_sha512' => AgentCaddyPackage::AMD64_SHA512,
        'minimum_version' => CaddyRelease::MINIMUM,
    ];
    expect(AgentCaddyPackage::input())->toBe($agentInput);
    $node = new Node(['name' => 'dev']);
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldReceive('run')->once()->with($node, 'caddy.install-package', $agentInput)
        ->andReturn(['changed' => true, 'version' => AgentCaddyPackage::RELEASE]);

    expect((new AgentCaddyPackage($agents))->install($node)->stdout)->toBe(AgentCaddyPackage::Changed."\n");
});
