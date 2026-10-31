<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Actions\Instances\ProjectDirectInstanceDestroyAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Routes\CaddyPublicationPlan;
use App\Domain\Routes\RouteWithdrawalArtifacts;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Record projection only. Every effect belongs to the bound native root. */
final readonly class InstanceDestroyProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct, private ProjectDirectInstanceDestroyAction $records, private RouteWithdrawalArtifacts $routes) {}

    public function input(Operation $operation): array
    {
        $input = $operation->input;
        $detail = ValidatedData::object($input['direct_destroy']);
        $detail['routes'] = array_map(static function (mixed $value): array {
            $artifact = ValidatedData::object($value);
            unset($artifact['php']);

            return $artifact;
        }, InstanceLifecycleInput::members(['members' => $detail['routes']]));

        $input['direct_destroy'] = $detail;
        if ($operation->status->terminal()) {
            return $input;
        }
        // Render on every bound read. Native publishers call this only after their model lock.
        // This does not grant foreign participants access to the root endpoint.
        $publications = [];
        foreach (InstanceLifecycleInput::members($input) as $member) {
            $instance = Instance::query()->with(['node', 'project'])->findOrFail(ValidatedData::integer($member['instance_id']));
            $artifact = $this->routes->forDevelopmentRemoval($instance, $instance->routes()->with('targets')->orderBy('routes.id')->get());
            unset($artifact['php']);
            $publications[] = $artifact;
        }

        return [...$input, 'direct_destroy' => $detail, 'withdrawal_publications' => $publications, 'caddy_publication' => CaddyPublicationPlan::forNode(Node::query()->findOrFail($operation->node_id))];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $this->direct->assertRun($operation, ValidatedData::string($data['run_id']));
            $result = ValidatedData::object($data['result'] ?? []);
            $code = $data['error_code'] ?? null;
            $status = $data['status'] ?? null;
            $valid = match ($status) {
                'succeeded' => $code === null,
                'failed' => is_string($code) && preg_match('/\A[a-z0-9_.-]{1,100}\z/D', $code) === 1
                    && $result === ['error_code' => $code],
                'running' => $code === null && $result === [],
                // Accepted destruction must finish or resume; it cannot discard its intent.
                default => false,
            };
            if (! $valid) {
                throw new ResourceOperationException('operation.result_invalid', 'Destroy requires its bounded native result.', 422);
            }
            $terminal = $operation->status->terminal();
            if ($status === 'succeeded' && ! $terminal) {
                $this->records->execute($operation->input, $result);
            }
            $projected = $this->direct->project($operation, $data, $result, 'instance-destroy');
            if ($status === 'failed' && ! $terminal) {
                InstanceRemoval::query()->whereKey($operation->input['removal_id'])->update([
                    'status' => InstanceRemovalStatus::Failed, 'error_code' => $code,
                ]);
            }

            return $projected;
        });
    }
}
