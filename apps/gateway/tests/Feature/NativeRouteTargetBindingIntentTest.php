<?php

declare(strict_types=1);

use App\Actions\Routes\SetRouteTargetAction;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\Instances\DevelopmentSourceAccess;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Tests\Support\FakeAgentCommandRunner;

/** @return array{Route,Instance} */
function native_binding_intent_fixture(): array
{
    $node = Node::query()->create(['name' => 'binding-node', 'public_ssh_host' => '192.0.2.91', 'wireguard_ip' => '10.44.0.91', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active', 'tld' => 'binding.test']);
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Binding', 'slug' => 'binding', 'repository_url' => 'https://example.test/binding.git', 'root' => 'public']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/srv/binding', 'status' => 'active', 'source_is_laravel' => false]);
    $route = Route::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'domain' => 'binding.private.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);

    return [$route, $instance];
}

it('records target association intent and retries without invoking source access, Metrics, agent commands or global projection locking', function (): void {
    [$route, $instance] = native_binding_intent_fixture();
    $this->mock(DevelopmentSourceAccess::class)->shouldNotReceive('grant');
    $this->mock(DevelopmentProjectionOperationLock::class)->shouldNotReceive('run');
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);
    $action = app(SetRouteTargetAction::class);

    $bound = $action->execute($route, $instance->id);
    $retried = $action->execute($bound, $instance->id);

    expect($bound->id)->toBe($route->id)
        ->and($retried->targets->sole()->instance_id)->toBe($instance->id)
        ->and($retried->status)->toBe(RouteStatus::Pending)
        ->and($route->targets()->count())->toBe(1)
        ->and($agents->calls)->toBe([]);
});

it('keeps Project ownership validation before association writes', function (): void {
    [$route, $instance] = native_binding_intent_fixture();
    $foreign = Project::query()->create(['name' => 'Foreign', 'slug' => 'foreign-binding', 'repository_url' => 'https://example.test/foreign.git', 'root' => 'public']);
    $instance->update(['project_id' => $foreign->id]);

    expect(fn () => app(SetRouteTargetAction::class)->execute($route, $instance->id))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('route.target_app_conflict'));
    expect($route->targets()->count())->toBe(0);
});
