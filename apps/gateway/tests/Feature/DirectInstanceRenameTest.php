<?php

declare(strict_types=1);

use App\Actions\Instances\RenameInstanceAction;
use App\Data\Instances\RenameInstanceData;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeFact;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->project = Project::query()->create(['name' => 'Rename proof', 'slug' => 'rename-proof', 'repository_url' => 'https://github.com/example/proof.git', 'type' => 'node-package', 'root' => '.']);
    $this->node = Node::query()->create(['name' => 'rename-dev', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'amd64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9', 'ssh_host_fingerprint' => 'SHA256:rename', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']);
    orbit_test_set_app_placement_role($this->node, false);
    $this->instance = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id, 'name' => 'proof', 'checkout_path' => '/srv/apps/rename-proof/proof', 'source_layout' => 'checkout', 'status' => 'active', 'branch' => 'old']);
    // The Node agent is online and reports the checkout with `feature` checked out.
    Carbon::setTestNow('2026-11-03 00:00:00');
    app(CacheAgentStateView::class)->putSubscriber(true, true, 1);
    seed_agent_view($this->node->id, []);
    $this->report = function (array $change = []): void {
        NodeFact::query()->updateOrCreate(['node_id' => $this->node->id, 'kind' => 'checkout', 'subject' => $this->instance->checkout_path], [
            'digest' => bin2hex(random_bytes(32)), 'observed_at' => now(), 'received_at' => now(),
            'data' => [...['top_level' => $this->instance->checkout_path, 'origin' => 'git@github.com:example/proof.git', 'branch' => 'feature'], ...$change]]);
    };
    ($this->report)();
});
afterEach(fn () => Carbon::setTestNow());

$rename = static fn (string $branch = 'feature', ?string $domain = null): Instance => app(RenameInstanceAction::class)->execute(
    test()->instance, new RenameInstanceData($branch, $domain));

it('records the branch the agent reports, at once and with no task', function () use ($rename): void {
    $instance = $rename();

    expect($instance->branch)->toBe('feature')->and($instance->branch_override)->toBe('feature')
        ->and(Operation::query()->count())->toBe(0);
});

it('refuses a branch the checkout has not checked out and keeps the recorded one', function () use ($rename): void {
    expect(fn () => $rename('other'))->toThrow(ResourceOperationException::class, 'has not checked out other');
    expect($this->instance->refresh()->branch)->toBe('old');
});

it("can't verify without a current report: no fact, or a silent agent", function (Closure $state) use ($rename): void {
    $state->call($this);

    expect(fn () => $rename())->toThrow(ResourceOperationException::class, "can't verify");
    expect($this->instance->refresh()->branch)->toBe('old');
})->with([
    'no fact' => [fn () => NodeFact::query()->delete()],
    'agent offline' => [function (): void {
        Carbon::setTestNow('2026-11-03 00:05:00');
        app(CacheAgentStateView::class)->putSubscriber(true, true, 1);
    }],
]);

it('refuses a checkout whose path or origin no longer matches', function (array $change) use ($rename): void {
    ($this->report)($change);

    expect(fn () => $rename())->toThrow(ResourceOperationException::class, 'no longer matches');
})->with([
    'another origin' => [['origin' => 'git@github.com:example/other.git']],
    'a masked origin' => [['origin' => 'https://REDACTED@github.com/example/proof.git']],
    'another top level' => [['top_level' => '/srv/apps/rename-proof/other']],
]);

it('writes nothing and keeps the override when the branch is already recorded', function () use ($rename): void {
    $this->instance->update(['branch' => 'feature', 'branch_override' => null]);
    $writes = 0;
    DB::listen(static function ($query) use (&$writes): void {
        $writes += (int) (preg_match('/^\s*(insert|update|delete)/i', $query->sql) === 1);
    });

    expect($rename()->branch_override)->toBeNull()->and($writes)->toBe(0);
});

it('rejects domain replacement before any check', function () use ($rename): void {
    expect(fn () => $rename('feature', 'new.example.test'))->toThrow(ResourceOperationException::class, 'Domain replacement requires');
    expect($this->instance->refresh()->branch)->toBe('old');
});

it('answers the HTTP rename with the recorded Instance', function (): void {
    $gateway = $this->markAsGateway(Node::query()->create(['name' => 'rename-gateway', 'status' => 'active',
        'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
    $this->node->accessibleNodes()->attach($gateway);
    $this->node->accessibleNodes()->attach($this->node);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);

    $this->postJson('/api/v1/instances/'.$this->instance->id.'/rename', ['branch' => 'feature'])->assertOk()
        ->assertJsonPath('data.id', $this->instance->id)->assertJsonPath('data.selected_branch', 'feature');
    expect(Operation::query()->count())->toBe(0);
});
