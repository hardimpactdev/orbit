<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\InstanceRemoval;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Complete public input for the native run. No worker fetches the Operation. */
final readonly class InstanceDestroyWorkflowInput
{
    /** @return array<string, mixed> */
    public function forOperation(Operation $operation): array
    {
        $input = $operation->input;
        $detail = ValidatedData::object($input['direct_destroy']);
        unset($detail['members']);
        $detail['routes'] = array_map(static function (array $route): array {
            unset($route['php']);
            $route['nodes'] = array_map(static function (array $node): array {
                unset($node['agent_sha256']);

                return $node;
            }, InstanceLifecycleInput::members(['members' => $route['nodes']]));

            return $route;
        }, InstanceLifecycleInput::members(['members' => $detail['routes']]));
        $removal = InstanceRemoval::query()->findOrFail(ValidatedData::string($input['removal_id']));

        return [
            'operation_id' => $operation->id,
            'kind' => $operation->kind,
            'node_id' => $operation->node_id,
            'intent' => [
                ...array_intersect_key($input, array_flip([
                    'instance_id', 'project_id', 'removal_id', 'node_id', 'environment', 'force',
                    'repository', 'repository_identity', 'protected_source_roots',
                    'participant_node_ids', 'parent_owns_routes',
                ])),
                'members' => array_map(static fn (array $member): array => array_intersect_key($member, array_flip([
                    'instance_id', 'project_id', 'node_id', 'environment', 'user', 'checkout_path',
                    'production_home', 'root', 'source_root', 'source_layout', 'repository_identity',
                    'branch', 'starting_commit', 'failed_creation', 'stored_status', 'source_prepare_id',
                    'registration_request_id', 'run_teardown',
                ])), InstanceLifecycleInput::members($input)),
                'direct_destroy' => $detail,
                'captured_sources' => $removal->native_sources,
            ],
        ];
    }
}
