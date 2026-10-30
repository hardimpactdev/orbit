<?php

declare(strict_types=1);

use App\Actions\Routes\AdmitRouteOperationAction;
use App\Actions\Routes\CreateRouteAction;
use App\Actions\Routes\DestroyRouteAction;
use App\Data\Routes\CreateRouteData;
use App\Data\Routes\UpdateRouteData;
use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Instances\InstanceState;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteRemovalProjector;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeRouteRemovalProjector;

beforeEach(function (): void {
    app()->instance(RouteRemovalProjector::class, new FakeRouteRemovalProjector);

    $this->node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'wireguard_ip' => '10.44.0.3',
        'tld' => 'dev.orbit',
    ]);
    $this->orbitApp = Project::query()->create([
        'name' => 'Docs',
        'slug' => 'docs',
        'repository_url' => 'git@example.test:docs.git',
        'root' => 'public',
    ]);
});

function route_record_pending(int $projectId, int $nodeId): Route
{
    return Route::query()->create([
        'project_id' => $projectId,
        'node_id' => $nodeId,
        'domain' => 'docs.orbit',
        'provenance' => 'explicit',
        'publication' => RoutePublication::Private,
        'status' => 'pending',
    ]);
}

describe('Route record events', function (): void {
    it('broadcasts route.created when a new App route is created', function (): void {
        Event::fake([RecordBroadcast::class]);

        $data = new CreateRouteData(
            domain: 'docs.orbit',
            publication: RoutePublication::Private,
            projectId: $this->orbitApp->id,
            instanceId: Instance::query()->create([
                'project_id' => $this->orbitApp->id,
                'node_id' => $this->node->id,
                'name' => 'docs',
                'checkout_path' => '/srv/docs',
                'status' => InstanceState::Active,
            ])->id,
        );

        $result = app(CreateRouteAction::class)->execute($data);

        expect($result['created'])->toBeTrue();
        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::RouteCreated
                && $event->id === $result['route']->id
                && $event->data['domain'] === 'docs.orbit',
        );
    });

    it('does not announce a pending domain change while recording its Operation', function (): void {
        $route = route_record_pending($this->orbitApp->id, $this->node->id);

        Event::fake([RecordBroadcast::class]);

        $operation = app(AdmitRouteOperationAction::class)->update($route, new UpdateRouteData(
            domainProvided: true,
            domain: 'docs-renamed.orbit',
            publicationProvided: false,
            publication: null,
        ));

        expect($operation->kind)->toBe('route.update')
            ->and($operation->status->value)->toBe('queued')
            ->and($route->refresh()->domain)->toBe('docs.orbit')
            ->and($operation->input['candidate_route_id'])->toBeInt();
        Event::assertNotDispatched(RecordBroadcast::class, fn (RecordBroadcast $event): bool => $event->type === RecordEventType::RouteUpdated);
    });

    it('broadcasts route.deleted with a minimal snapshot when a route is removed', function (): void {
        $route = route_record_pending($this->orbitApp->id, $this->node->id);

        Event::fake([RecordBroadcast::class]);

        app(DestroyRouteAction::class)->execute($route);

        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::RouteDeleted
                && $event->id === $route->id
                && $event->data === ['id' => $route->id, 'domain' => 'docs.orbit'],
        );
    });
});
