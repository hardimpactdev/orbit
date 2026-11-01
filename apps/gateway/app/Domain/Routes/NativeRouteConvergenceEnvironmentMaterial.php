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
final readonly class NativeRouteConvergenceEnvironmentMaterial
{
    public function __construct(private InstanceEnvironmentContextResolver $contexts, private InstanceEnvironmentStore $store,
        private InstanceEnvironmentRenderer $renderer) {}

    /**
     * @param  array<string,mixed>|null  $intent
     * @return list<array{instance_id: int, name: string, input: array<string, mixed>}>
     */
    public function forNode(Operation $operation, int $ownerNodeId, ?array $intent = null): array
    {
        $intent ??= ValidatedData::object($operation->input['route_convergence'] ?? []);
        $rollingBack = ($operation->state['native_route_rollback'] ?? false) === true;
        $participants = ValidatedData::integerList($operation->input['participant_node_ids'] ?? $intent['node_ids'] ?? []);
        if (! in_array($ownerNodeId, $participants, true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Private Route material belongs to an admitted workload Node.', 403);
        }
        $route = Route::query()->findOrFail(ValidatedData::integer($intent['route_id']));

        return $this->forRoute($route, $ownerNodeId, $intent, $rollingBack);
    }

    /** Pure rendering for full-input parents; never resolves an Operation or calls a Node.
     * @param  array<string,mixed>  $intent
     * @param  array<int,Route>  $candidates  Prospective replacements keyed by original Route id.
     * @param  array<int,Instance>  $placements  Captured Instance placements supplied by an owning parent.
     * @return list<array{instance_id:int,name:string,input:array<string,mixed>}>
     */
    public function forRoute(Route $route, int $ownerNodeId, array $intent, bool $rollingBack = false, array $candidates = [], array $placements = [], bool $urlsOnly = false): array
    {
        $writes = [];
        foreach (RouteExecutionProof::objects($intent['workloads'] ?? []) as $member) {
            if ($member['node_id'] !== $ownerNodeId) {
                continue;
            }
            if (isset($member['route_id'])) {
                $route = $candidates[ValidatedData::integer($member['route_id'])] ?? Route::query()->findOrFail(ValidatedData::integer($member['route_id']));
            }
            $instance = $placements[ValidatedData::integer($member['instance_id'])] ?? Instance::query()->with(['node', 'project'])->findOrFail(ValidatedData::integer($member['instance_id']));
            if ($instance->node_id !== $ownerNodeId) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The environment workload moved from its admitted Node.', 403);
            }
            $urlsOnly = $urlsOnly || $route->hasWebRoot();
            if (! $urlsOnly && $instance->source_is_laravel) {
                $context = $this->contexts->resolveForBoundRoute($instance, $rollingBack ? $instance->authoritativeRoute() : $route, $rollingBack ? InstanceEnvironmentRouteDomain::Authoritative : InstanceEnvironmentRouteDomain::Candidate);
                $values = $this->store->values($instance);
                if ($values === []) {
                    throw new ResourceOperationException('env.configuration_missing', 'The Instance has no stored environment configuration.', 409);
                }
                $writes[] = ['instance_id' => $instance->id, 'name' => 'instance.write-environment', 'input' => [
                    'base' => $context->path, 'user' => $context->environment === 'production' ? $context->executionUser : app(RouteRenderContext::class)->user($instance->node), 'maximum' => InstanceEnvironmentValidator::MaximumFileBytes,
                    'require_home' => $context->environment === 'production', 'content' => $this->renderer->render($context, $values),
                    'mode' => 'write', 'file' => '.env', 'managed_keys' => [],
                ]];
            }
            if ($instance->selected_php_version === null) {
                continue;
            }
            $routes = RouteWebRoot::applicationUrlCandidates($instance)->reject(static fn (Route $stored): bool => isset($candidates[$stored->id]));
            foreach ($candidates as $candidate) {
                if ($candidate->status->isAuthoritative() && $candidate->hasWebRoot() && $candidate->targets->contains('instance_id', $instance->id)) {
                    $routes->push($candidate);
                }
            }
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
                            'owner' => app(RouteRenderContext::class)->user($instance->node), 'app_key_stored' => false, 'require_artisan' => true])]];
            }
        }

        return $writes;
    }
}
