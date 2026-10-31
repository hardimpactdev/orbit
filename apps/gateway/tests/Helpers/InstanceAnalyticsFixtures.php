<?php

declare(strict_types=1);

use App\Actions\Routes\CreateRouteAction;
use App\Data\Routes\CreateRouteData;
use App\Domain\Analytics\AnalyticsIntentProjection;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Tests\Support\FakeRoutePublicationSocket;

function instance_analytics_node(string $name, string $wireguardIp, ?Cluster $cluster, RoleName $role): Node
{
    $octet = substr($wireguardIp, strrpos($wireguardIp, '.') + 1);
    $node = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => "192.0.2.{$octet}",
        'wireguard_ip' => $wireguardIp,
        'lan_ip' => $cluster instanceof Cluster ? "10.10.0.{$octet}" : null,
        'cluster_id' => $cluster?->id,
        'user' => 'orbit',
    ]);
    $node->roles()->create([
        'cluster_id' => in_array($role, [RoleName::Router, RoleName::Ingress], true) ? $cluster?->id : null,
        'role' => $role,
        'status' => LifecycleStatus::Active,
    ]);

    return $node;
}

function instance_analytics_instance(Project $project, Node $node, string $name): Instance
{
    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $name,
        'checkout_path' => "/srv/orbit/apps/{$project->slug}/{$name}",
        'branch' => $name,
        'starting_commit' => str_repeat('a', 40),
        'status' => InstanceState::Active,
        'environment' => 'production',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'production_home' => "/var/www/{$project->slug}-{$name}",
        'production_user' => "orbit-{$project->slug}",
        'selected_php_version' => '8.5',
    ]);
}

/** A Node holds one production App instance of an App, so every further instance gets a Node of its own. */
function instance_analytics_extra_instance(string $name): Instance
{
    static $octet = 60;
    $octet++;

    return instance_analytics_instance(
        test()->orbitApp,
        instance_analytics_node("edge-{$name}", "10.44.0.{$octet}", test()->cluster, RoleName::AppProd),
        $name,
    );
}

function instance_analytics_app_route(
    Instance $instance,
    string $domain,
    RoutePublication $publication,
    bool $activate = true,
): Route {
    $route = app(CreateRouteAction::class)->execute(new CreateRouteData(
        projectId: $instance->project_id,
        domain: $domain,
        publication: $publication,
        instanceId: $instance->id,
        nodeId: null,
        clusterId: null,
    ))['route'];

    if ($activate) {
        $route->update(['status' => RouteStatus::Active]);
    }

    return $route->refresh();
}

final readonly class InstanceAnalyticsProjectionOwner implements DevelopmentProjectionOperationLock
{
    public function run(Closure $operation): mixed
    {
        return $operation();
    }
}

/** Seed an observed tracking Route without invoking the retired PHP enable executor. */
function instance_analytics_tracking(Instance $instance, ?string $domain = null): Route
{
    $owner = $instance->unsetRelation('routes')->authoritativeRoute();
    $route = Route::query()->create([
        'kind' => RouteKind::AnalyticsTracking,
        'domain' => $domain ?? 'analytics.'.$owner->domain,
        'node_id' => $owner->node_id, 'cluster_id' => $owner->cluster_id,
        'publication' => $owner->publication, 'provenance' => RouteProvenance::Explicit,
        'status' => RouteStatus::Pending, 'sites_published' => false,
        'replacement_step' => $owner->publication === RoutePublication::Public ? RouteReplacementStep::PublicActivated : null,
    ]);
    $route->analyticsTracking()->create(['instance_id' => $instance->id]);
    $route->update(['status' => RouteStatus::Active, 'sites_published' => true]);

    return $route->refresh();
}

function analytics_intent_socket(): FakeRoutePublicationSocket
{
    $signer = Mockery::mock(LeafCertificateSigner::class);
    $signer->shouldReceive('rootCertificate')->andReturn("-----BEGIN CERTIFICATE-----\npublic-root\n-----END CERTIFICATE-----");
    app()->instance(LeafCertificateSigner::class, $signer);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => [
        'kind' => 'started', 'workflowName' => $request['payload']['workflowIdOrName'],
        'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676',
    ]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'analytics-test-token']);

    return $socket;
}

function analytics_intent_result(string $status, array $result = [], ?string $error = null): array
{
    return ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'status' => $status,
        'started_at' => '2026-10-30T12:00:00.000Z', 'finished_at' => '2026-10-30T12:00:01.000Z',
        'result' => $result, 'error_code' => $error];
}

function analytics_intent_success(): array
{
    return ['ready' => true, 'enabled' => true, 'domain' => 'shop.example.com', 'timezone' => 'Europe/Amsterdam', 'readable' => true];
}

function analytics_intent_start(mixed $test, array $body = []): Operation
{
    analytics_intent_socket();
    $id = $test->postJson($test->url, $body)->assertAccepted()->json('data.id');
    $operation = Operation::query()->findOrFail($id);
    app(AnalyticsIntentProjection::class)->apply($operation, analytics_intent_result('running'));

    return $operation->refresh();
}
