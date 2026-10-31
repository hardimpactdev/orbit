<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentRenderer;
use App\Domain\Instances\Environment\InstanceEnvironmentRouteDomain;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\Environment\InstanceEnvironmentValidator;
use App\Domain\Instances\Environment\LaravelApplicationKey;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;

/** Renders the existing Instance environment material inside the private callback only. */
final readonly class NativeRouteEnvironmentMaterial
{
    public function __construct(private InstanceEnvironmentContextResolver $contexts, private InstanceEnvironmentStore $store,
        private InstanceEnvironmentRenderer $renderer) {}

    /** @return list<array{instance_id: int, name: string, input: array<string, mixed>}> */
    public function forNode(Operation $operation, int $ownerNodeId): array
    {
        $removal = ValidatedData::object($operation->input['route_removal'] ?? []);
        if ($removal !== []) {
            $member = array_find($removal['nodes'] ?? [], static fn (array $node): bool => $node['node_id'] === $ownerNodeId);
            $route = Route::query()->findOrFail($operation->target_id);
            $workloads = [];
            foreach (is_array($member) ? ($member['url_instance_ids'] ?? []) : [] as $instanceId) {
                $instance = Instance::query()->findOrFail($instanceId);
                $workloads[] = ['instance_id' => $instance->id, 'node_id' => $ownerNodeId, 'production' => $instance->placedOnAppProd()];
            }
            $intent = ['route_id' => $route->id, 'node_ids' => $operation->input['participant_node_ids'] ?? [], 'workloads' => $workloads];
        }
        $update = ValidatedData::object($operation->input['route_update'] ?? []);
        $rollingBack = $removal !== [] || ($operation->state['native_route_rollback'] ?? false) === true;
        $intent ??= $update === [] ? ValidatedData::object($operation->input['route_convergence'] ?? [])
            : ValidatedData::object($update[$rollingBack ? 'previous' : 'desired']);
        $target = ValidatedData::object($operation->input['route_target'] ?? []);
        if ($target !== []) {
            $intent = $target;
        }
        $participants = ValidatedData::integerList($operation->input['participant_node_ids'] ?? $intent['node_ids'] ?? []);
        if (! in_array($ownerNodeId, $participants, true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Private Route material belongs to an admitted workload Node.', 403);
        }
        $route = Route::query()->findOrFail(ValidatedData::integer($intent['route_id']));
        $writes = [];
        foreach (array_map(ValidatedData::object(...), $intent['workloads'] ?? []) as $member) {
            if ($member['node_id'] !== $ownerNodeId) {
                continue;
            }
            if ($target !== []) {
                $route = Route::query()->findOrFail(ValidatedData::integer($member['route_id']));
            }
            $instance = Instance::query()->with(['node', 'project'])->findOrFail(ValidatedData::integer($member['instance_id']));
            if ($instance->node_id !== $ownerNodeId) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The environment workload moved from its admitted Node.', 403);
            }
            $urlsOnly = $removal !== [] || $route->hasWebRoot() || ($update['mode'] ?? null) === 'web_root';
            if (! $urlsOnly) {
                $context = $this->contexts->resolveForBoundRoute($instance, $route, $rollingBack ? InstanceEnvironmentRouteDomain::Authoritative : InstanceEnvironmentRouteDomain::Candidate);
                $values = $this->store->values($instance);
                if ($values === [] && $instance->source_is_laravel) {
                    throw new ResourceOperationException('env.configuration_missing', 'The Instance has no stored environment configuration.', 409);
                }
                if ($instance->source_is_laravel) {
                    $writes[] = ['instance_id' => $instance->id, 'name' => 'instance.write-environment', 'input' => [
                        'base' => $context->path, 'user' => $context->executionUser, 'maximum' => InstanceEnvironmentValidator::MaximumFileBytes,
                        'require_home' => $context->environment === 'production', 'content' => $this->renderer->render($context, $values),
                        'mode' => 'write', 'file' => '.env', 'managed_keys' => [],
                    ]];
                }
            }
            if ($instance->selected_php_version === null) {
                continue;
            }
            $routes = RouteWebRoot::applicationUrlCandidates($instance);
            if (! $rollingBack && $route->hasWebRoot() && ! $routes->contains('id', $route->id)) {
                $routes->push($route);
            }
            $storedName = $instance->environmentValues()->where('env_key', 'APP_NAME')->first()?->env_value;
            $hasName = is_string($storedName) && $storedName !== '' && ! str_contains($storedName, '{{');
            foreach (RouteWebRoot::applicationUrlRoutes($instance, $routes) as $directory => $winner) {
                $common = ['url' => 'https://'.$winner->domain,
                    'app_key' => 'APP_KEY='.InstanceEnvironmentRenderer::quote(LaravelApplicationKey::generate()),
                    'app_name' => 'APP_NAME='.InstanceEnvironmentRenderer::quote($hasName ? $storedName : $instance->project->name),
                    'app_name_stored' => $hasName];
                $writes[] = ['instance_id' => $instance->id,
                    'name' => $member['production'] ? 'instance.write-directory-url' : 'instance.write-development-url',
                    'input' => [...$common, ...($member['production']
                        ? ['directory' => (string) $directory, 'user' => $instance->production_user, 'home' => $instance->production_home]
                        : ['directory' => rtrim($instance->checkout_path, '/').($directory === '' ? '' : '/'.$directory),
                            'owner' => $instance->node->user, 'app_key_stored' => false, 'require_artisan' => true])]];
            }
        }

        return $writes;
    }
}
