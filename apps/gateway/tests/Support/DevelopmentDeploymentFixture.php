<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Tools\PrepareVpEnvironmentAction;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Infrastructure\Instances\RemoteDevelopmentDeployment;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Mockery;

/** Gateway deployment intent and records. Rust tests own the checkout and step processes. */
final class DevelopmentDeploymentFixture
{
    public readonly string $home;

    public readonly Instance $instance;

    public readonly RemoteDevelopmentDeployment $deployment;

    public readonly FakeAgentCommandRunner $agents;

    public function __construct()
    {
        $this->home = '/srv/apps/dev935/default';
        $node = Node::query()->create(['name' => 'dev935', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.35', 'wireguard_ip' => '10.44.0.35']);
        $node->roles()->create(['role' => 'app-dev', 'status' => 'active']);
        $project = Project::query()->create(['name' => 'dev935', 'slug' => 'dev935', 'type' => 'monorepo', 'repository_url' => 'https://example.test/dev935.git', 'default_branch' => 'main', 'root' => 'public']);
        $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => $this->home, 'source_layout' => 'checkout', 'branch' => 'main', 'status' => 'active']);
        $accounts = Mockery::mock(ManagedUserAccountResolver::class);
        $accounts->shouldReceive('resolve')->andReturn(new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));
        $this->agents = new FakeAgentCommandRunner()
            ->answer('instances.resolve-development-target', ['commit' => str_repeat('b', 40)])
            ->answer('instances.checkout-development-default', static fn (array $input): array => ['commit' => $input['commit']])
            ->answer('instances.run-development-step', ['exit_code' => 0, 'timed_out' => false, 'output' => [], 'truncated' => false]);
        $this->deployment = new RemoteDevelopmentDeployment(
            $this->agents, $accounts, app(CheckoutRemovalBoundary::class), app(RepositoryReadAccess::class), app(PrepareVpEnvironmentAction::class),
        );
    }
}
