<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\Routes\RouteStatus;
use App\Domain\Routes\RouteWebRoot;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\ApplicationDirectory;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Resolves and validates protected write material in request memory. Reads never become receipts. */
final readonly class EnvironmentSyncMaterial
{
    public function __construct(
        private InstanceEnvironmentContextResolver $contexts,
        private InstanceEnvironmentStore $store,
        private InstanceEnvironmentRenderer $renderer,
        private InstanceOperationPreflight $preflight,
        private InstanceTestEnvironment $testing,
        private InstanceEnvironmentReader $reader,
        private InstanceEnvironmentImporter $importer,
        private InstanceRouteOwnership $routeOwnership,
    ) {}

    public function context(Operation $operation): InstanceEnvironmentContext
    {
        if ($operation->kind !== 'instance.sync_environment' || $operation->target_type !== 'instance'
            || ($operation->input['instance_id'] ?? null) !== $operation->target_id
            || array_diff(array_keys($operation->input ?? []), ['instance_id', 'route_domain']) !== []) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The environment intent is invalid.', 422);
        }
        $instance = Instance::query()->findOrFail($operation->target_id);
        $transfer = $this->transfer($operation);
        $route = $this->routeOwnership->route($operation);
        if ($route !== null) {
            $domain = InstanceEnvironmentRouteDomain::tryFrom(ValidatedData::string($operation->input['route_domain'] ?? null));
            if ($domain === null) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The Route domain selector is invalid.', 422);
            }
            $own = Route::query()->whereNull('web_root')->whereIn('status', [RouteStatus::Active, RouteStatus::Activating])
                ->whereHas('targets', static fn ($query) => $query->where('instance_id', $instance->id))->orderBy('id')->get();
            if ($domain === InstanceEnvironmentRouteDomain::Candidate && ! $route->hasWebRoot()) {
                $selected = $route;
            } else {
                $selected = $own->first();
                if ($own->count() > 1) {
                    throw new ResourceOperationException('env.owner_unavailable', 'The Instance has ambiguous authoritative Routes.', 409);
                }
            }

            return $this->contexts->resolveForBoundRoute($instance, $selected, $domain);
        }
        if (array_key_exists('route_domain', $operation->input) || $operation->node_id !== ($transfer->destination_node_id ?? $instance->node_id)
            || ($operation->input['instance_id'] ?? null) !== $instance->id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The environment target does not match admitted placement.', 422);
        }
        $parentId = $operation->getAttribute('parent_operation_id');
        $parent = is_int($parentId) ? Operation::query()->findOrFail($parentId) : null;
        $members = $parent?->kind === 'instance.register' ? ($parent->input['members'] ?? []) : [];
        if (! is_array($members)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The registration membership is invalid.', 422);
        }
        $foundMember = array_find($members, static fn (mixed $member): bool => is_array($member) && ($member['instance_id'] ?? null) === $instance->id);
        $member = is_array($foundMember) ? ValidatedData::object($foundMember) : null;
        if ($parent !== null && ($parent->target_type !== 'instance'
            || ($transfer === null && $parent->node_id !== $operation->node_id)
            || ($member === null && ($parent->target_id !== $instance->id || ($parent->input['instance_id'] ?? null) !== $instance->id))
            || ($member !== null && ($member['checkout'] ?? null) !== $instance->checkout_path)
            || ! in_array($parent->kind, ['instance.create', 'instance.clone', 'instance.transfer', 'instance.transfer_destination',
                'instance.register', 'instance.rename', 'instance.deploy', 'instance.rollback', 'instance.synchronize_environment'], true))) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The parent does not own this environment target.', 422);
        }

        if ($parent?->kind === 'instance.clone' && ($parent->input['candidate_id'] ?? null) !== $instance->clone_candidate_id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The clone source changed after admission.', 422);
        }

        if ($transfer !== null) {
            $destination = Node::query()->findOrFail($transfer->destination_node_id);
            $route = Route::query()->findOrFail($transfer->destination_route_id ?? $transfer->source_route_id);
            $ownRoute = $route->hasWebRoot() ? null : $route;

            return new InstanceEnvironmentContext(instanceId: $instance->id, projectId: $instance->project_id,
                nodeId: $destination->id, environment: 'development',
                path: $instance->source_is_laravel === true ? ApplicationDirectory::resolve($transfer->destination_path, $instance->root ?? $instance->project->root) : $transfer->destination_path,
                executionUser: $destination->user, laravel: $instance->source_is_laravel === true,
                routeId: $ownRoute?->id, routeDomain: $ownRoute === null ? null : $transfer->destination_domain,
                nodeStatus: $destination->status->value, node: $destination);
        }

        return $parent?->kind === 'instance.clone'
            ? $this->contexts->resolveForClone($instance, requireActiveNode: true)
            : $this->contexts->resolve($instance, requireActiveNode: true);
    }

    public function onlyUrls(Operation $operation): bool
    {
        return $this->routeOwnership->onlyUrls($operation);
    }

    /** @return list<array{key:string,name:string,input:array<string,mixed>}> */
    public function routeUrls(Operation $operation): array
    {
        if ($this->routeOwnership->route($operation) === null) {
            return [];
        }
        $instance = Instance::query()->with(['node', 'project'])->findOrFail($operation->target_id);
        if ($instance->selected_php_version === null) {
            return [];
        }
        $result = [];
        $reference = ['$secret' => 'instance.environment_sync', 'id' => $instance->id];
        foreach (RouteWebRoot::applicationUrlRoutes($instance, RouteWebRoot::applicationUrlCandidates($instance)) as $directory => $route) {
            $key = 'route.url.'.substr(hash('sha256', (string) $directory), 0, 16);
            $input = ['url' => 'https://'.$route->domain, 'app_key' => $reference, 'app_name' => $reference, 'app_name_stored' => $reference];
            if ($instance->placedOnAppProd()) {
                $result[] = ['key' => $key, 'name' => 'instance.write-directory-url', 'input' => [...$input,
                    'directory' => (string) $directory, 'user' => $instance->production_user, 'home' => $instance->production_home]];
            } else {
                $result[] = ['key' => $key, 'name' => 'instance.write-development-url', 'input' => [...$input,
                    'directory' => rtrim($instance->checkout_path, '/').($directory === '' ? '' : '/'.$directory),
                    'owner' => $instance->node->user, 'app_key_stored' => false, 'require_artisan' => true]];
            }
        }

        return $result;
    }

    public function snapshot(InstanceEnvironmentContext $context): InstanceEnvironmentSynchronizationSnapshot
    {
        $instance = Instance::query()->findOrFail($context->instanceId);
        if ($instance->node_id !== $context->nodeId || $context->routeDomainSource !== null) {
            $values = $this->store->values($instance);
            if ($context->routeDomainSource !== null && $values === []) {
                throw new ResourceOperationException('env.configuration_missing', 'The Instance has no stored environment configuration.', 409);
            }

            return new InstanceEnvironmentSynchronizationSnapshot($values, $instance->environment_owned_keys ?? []);
        }

        return $context->cloning
            ? $this->store->cloneSynchronizationSnapshot($context)
            : $this->store->synchronizationSnapshot($context);
    }

    /** @param list<string> $keys */
    public function recordSynchronizedKeys(Operation $operation, array $keys): void
    {
        $context = $this->context($operation);
        if ($context->routeDomainSource !== null) {
            sort($keys, SORT_STRING);
            Instance::query()->whereKey($context->instanceId)->update(['environment_owned_keys' => json_encode(array_values(array_unique($keys)), JSON_THROW_ON_ERROR)]);
        } else {
            $this->store->recordSynchronizedKeys($context, $keys);
        }
    }

    public function testing(InstanceEnvironmentContext $context): ?InstanceTestEnvironmentPlan
    {
        return $this->testing->plan($context->instanceId, $this->snapshot($context)->values());
    }

    public function transfer(Operation $operation): ?InstanceTransfer
    {
        $parentId = $operation->getAttribute('parent_operation_id');
        $parent = is_int($parentId) ? Operation::query()->findOrFail($parentId) : null;
        if ($parent?->kind === 'instance.transfer_destination') {
            if ($parent->target_type !== 'instance' || $parent->target_id !== $operation->target_id
                || $parent->node_id !== $operation->node_id || ($parent->input['instance_id'] ?? null) !== $operation->target_id) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The destination parent does not own this environment target.', 422);
            }
            $destinationParent = $parent;
            $parentId = $parent->getAttribute('parent_operation_id');
            $parent = is_int($parentId) ? Operation::query()->findOrFail($parentId) : null;
        }
        if ($parent?->kind !== 'instance.transfer') {
            return null;
        }
        $transfer = InstanceTransfer::query()->findOrFail(ValidatedData::string($parent->input['transfer_id']));
        if ($transfer->instance_id !== $operation->target_id || $transfer->destination_node_id !== $operation->node_id
            || $parent->target_type !== 'instance' || $parent->target_id !== $operation->target_id
            || ($parent->input['instance_id'] ?? null) !== $operation->target_id
            || $parent->node_id !== $transfer->source_node_id
            || (isset($destinationParent) && ($destinationParent->input['transfer_id'] ?? null) !== $transfer->id)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The transfer destination does not match this environment child.', 422);
        }

        return $transfer;
    }

    /** Imports missing source keys and records their inventory before writing the destination. */
    public function prepare(Operation $operation): void
    {
        $this->context($operation);
        $transfer = $this->transfer($operation);
        if ($transfer === null || $transfer->cutover_at !== null) {
            return;
        }
        $instance = Instance::query()->findOrFail($operation->target_id);
        $context = $this->contexts->resolve($instance, requireActiveNode: true);
        try {
            $imported = $this->importer->parse($this->reader->read($context));
        } catch (ResourceOperationException $failure) {
            if ($failure->errorCode !== 'env.import_source_missing') {
                throw $failure;
            }
            $imported = [];
        }
        $missing = array_diff_key($imported, $this->store->values($instance));
        if ($missing !== []) {
            DB::transaction(function () use ($transfer, $context, $missing): void {
                $transfer->update(['imported_environment_keys' => array_values(array_unique([
                    ...($transfer->imported_environment_keys ?? []), ...array_keys($missing),
                ]))]);
                $this->store->import($context, $missing, replace: false);
            });
        }
    }

    /** @return array{contents: string, values: array<string,string>, keys: list<string>} */
    public function render(Operation $operation, bool $testing): array
    {
        $context = $this->context($operation);
        $snapshot = $this->snapshot($context);
        $transfer = $this->transfer($operation);
        $capacity = $transfer !== null || $context->routeDomainSource !== null ? InstanceEnvironmentValidator::MaximumFileBytes * 2 : ($context->cloning ? $this->store->cloneSynchronizationCapacity($context) : $this->store->synchronizationCapacity($context));
        if ($context->cloning || $transfer !== null || $context->routeDomainSource !== null) {
            $this->preflight->assertEnvironmentWritable($context, $capacity);
        } elseif (! $testing) {
            try {
                $existing = $this->preflight->readWritableEnvironment($context, $capacity);
                preg_match_all('/^[ \t]*(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_.]*)[ \t]*=/m', $existing, $matches);
                $unowned = $snapshot->unownedKeysIn(array_values(array_unique($matches[1])));
                if ($unowned !== []) {
                    throw new ResourceOperationException('env.sync_would_drop_keys', 'The workload environment file has keys that stored configuration lacks. Store them before synchronization.', 409, details: ['keys' => implode(',', $unowned)]);
                }
            } catch (ResourceOperationException $failure) {
                if ($failure->errorCode !== 'env.import_source_missing') {
                    throw $failure;
                }
            }
        }
        $values = $snapshot->values();
        if ($testing) {
            $plan = $this->testing($context);
            if ($plan === null) {
                throw new ResourceOperationException('operation.execution_forbidden', 'This Instance has no managed testing environment.', 422);
            }
            $values = $plan->values;
        }

        return ['contents' => $this->renderer->render($context, $values), 'values' => $values, 'keys' => array_keys($values)];
    }
}
