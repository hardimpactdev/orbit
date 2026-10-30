<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Data\Instances\RenameInstanceData;
use App\Domain\Instances\InstanceLifecycleAvailability;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Routes\ReservedPrivateHostname;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Operation;
use App\Models\Route;
use Illuminate\Support\Facades\DB;

final readonly class RenameInstanceAction
{
    public function __construct(
        private StartOperationAction $operations,
        private OperationIdempotency $idempotency,
        private ReservedPrivateHostname $reservedHostnames,
        private ManagedUserAccountResolver $accounts,
        private CheckoutRemovalBoundary $boundaries,
    ) {}

    public function execute(Instance $instance, RenameInstanceData $data, ?string $caller = null, ?string $requestId = null): Operation
    {
        if (($replay = $this->idempotency->replay('instance.rename')) !== null) {
            return $replay;
        }
        if (app(DirectOperation::class)->writer('instance.rename') === 'direct') {
            if ($data->domain !== null) {
                throw new ResourceOperationException('operation.kind_unavailable', 'Direct rename supports branch recording until Route composition is available.', 503);
            }
        } else {
            app(InstanceLifecycleAvailability::class)->assertAvailable('instance.rename');
        }

        return DB::transaction(fn (): Operation => $this->admit($instance->refresh(), $data, $caller, $requestId));
    }

    private function admit(Instance $instance, RenameInstanceData $data, ?string $caller, ?string $requestId): Operation
    {
        if (! $instance->placedOnAppDev() || $instance->source_layout !== InstanceSourceLayout::Checkout->value) {
            throw new ResourceOperationException('instance.rename_unsupported', 'Rename requires a development checkout Instance.', 409);
        }
        if ($instance->status === InstanceState::Removing
            || InstanceTransfer::query()->where('instance_id', $instance->id)->where('status', '!=', 'completed')->exists()
            || Instance::query()->where('clone_candidate_id', $instance->id)->whereNull('clone_completed_at')->exists()
            || $instance->clone_candidate_id !== null && $instance->clone_completed_at === null) {
            throw new ResourceOperationException('instance.lifecycle_busy', 'The Instance is busy with another lifecycle operation.', 409);
        }
        if ($instance->status !== InstanceState::Active) {
            throw new ResourceOperationException('instance.rename_inactive', 'Rename requires an active Instance.', 409);
        }
        $routeId = null;

        if ($data->domain !== null) {
            $this->reservedHostnames->assertAvailable($data->domain);
            $routes = $instance->routes()->whereNull('web_root')->with('targets')->get();
            // During replacement both Routes own the same target. Resume through its original Route.
            $roots = $routes->filter(static fn (Route $route): bool => $route->replaces_route_id === null);
            $route = $roots->count() === 1 ? $roots->sole() : null;
            if (! $route instanceof Route || ! $route->isApp() || $route->project_id !== $instance->project_id
                || $route->targets->count() !== 1 || $route->targets->sole()->instance_id !== $instance->id
                || $routes->contains(static fn (Route $other): bool => $other->id !== $route->id && $other->replaces_route_id !== $route->id)) {
                throw new ResourceOperationException('instance.route_required', 'Rename requires the Instance’s own single-target Project Route.', 409);
            }
            $routeId = $route->id;
            if (Route::query()->where('domain', $data->domain)->whereKeyNot($route->id)->exists()) {
                throw new ResourceOperationException('route.domain_conflict', 'Another Route owns the requested domain.', 409);
            }
        }

        $account = $this->accounts->resolve($instance->node);

        return $this->operations->execute('instance.rename', 'instance', $instance->id, $instance->node_id, [
            'instance_id' => $instance->id, 'project_id' => $instance->project_id,
            'branch' => $data->branch, 'domain' => $data->domain, 'route_id' => $routeId,
            'checkout' => $instance->checkout_path, 'repository_identity' => $instance->project->repository_identity,
            'repository' => $instance->project->repository_url, 'source_layout' => $instance->source_layout,
            'prepare_id' => $instance->source_prepare_id, 'seed_repository' => $instance->seed_repository,
            'user' => $account->user, 'group' => $account->group,
            'source_root' => $this->boundaries->instanceRoot($instance, $account)->value,
            ...(app(DirectOperation::class)->writer('instance.rename') === 'direct'
                ? ['agent_sha256' => app(AgentDelivery::class)->pin($instance->node)] : []),
        ], $caller, $requestId);
    }
}
