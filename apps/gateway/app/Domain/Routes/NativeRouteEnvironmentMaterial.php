<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Instances\Environment\InstanceEnvironmentRenderer;
use App\Domain\Instances\Environment\LaravelApplicationKey;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Renders the existing Instance environment material inside the private callback only. */
final readonly class NativeRouteEnvironmentMaterial
{
    /** @return list<array{instance_id: int, name: string, input: array<string, mixed>}> */
    public function forNode(Operation $operation, int $ownerNodeId): array
    {
        $removal = ValidatedData::object($operation->input['route_removal']);
        $member = array_find(RouteExecutionProof::objects($removal['nodes']), static fn (array $node): bool => $node['node_id'] === $ownerNodeId);
        $participants = ValidatedData::integerList($operation->input['participant_node_ids']);
        if (! in_array($ownerNodeId, $participants, true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Private Route material belongs to an admitted workload Node.', 403);
        }
        $writes = [];
        foreach (ValidatedData::integerList($member['url_instance_ids'] ?? []) as $instanceId) {
            $instance = Instance::query()->with(['node', 'project'])->findOrFail($instanceId);
            if ($instance->node_id !== $ownerNodeId) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The environment workload moved from its admitted Node.', 403);
            }
            if ($instance->selected_php_version === null) {
                continue;
            }
            $routes = RouteWebRoot::applicationUrlCandidates($instance);
            $storedName = $instance->environmentValues()->where('env_key', 'APP_NAME')->first()?->env_value;
            $hasName = is_string($storedName) && $storedName !== '' && ! str_contains($storedName, '{{');
            foreach (RouteWebRoot::applicationUrlRoutes($instance, $routes) as $directory => $winner) {
                $common = ['url' => 'https://'.$winner->domain,
                    'app_key' => 'APP_KEY='.InstanceEnvironmentRenderer::quote(LaravelApplicationKey::generate()),
                    'app_name' => 'APP_NAME='.InstanceEnvironmentRenderer::quote($hasName ? $storedName : $instance->project->name),
                    'app_name_stored' => $hasName];
                $writes[] = ['instance_id' => $instance->id,
                    'name' => $instance->placedOnAppProd() ? 'instance.write-directory-url' : 'instance.write-development-url',
                    'input' => [...$common, ...($instance->placedOnAppProd()
                        ? ['directory' => (string) $directory, 'user' => $instance->production_user, 'home' => $instance->production_home]
                        : ['directory' => rtrim($instance->checkout_path, '/').($directory === '' ? '' : '/'.$directory),
                            'owner' => $instance->node->user, 'app_key_stored' => false, 'require_artisan' => true])]];
            }
        }

        return $writes;
    }
}
