<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/** Records a pending Grafana password; the native root alone changes Grafana. */
final readonly class StartMetricsCredentialResetAction
{
    public function __construct(private StartOperationAction $operations, private OperationIdempotency $idempotency,
        private MetricsCredentialResetMaterial $material) {}

    public function execute(): Operation
    {
        if (($replay = $this->idempotency->replay('metrics.credentials_reset')) !== null) {
            return $replay;
        }

        return DB::transaction(function (): Operation {
            $assignments = NodeRole::query()->where('role', RoleName::Metrics)->with('node')->limit(2)->get();
            if ($assignments->count() !== 1) {
                throw new ResourceOperationException('metrics.assignment_missing', 'Credential reset requires one Metrics assignment.', 409);
            }
            $assignment = $assignments->sole();
            $node = Node::query()->whereKey($assignment->node_id)->firstOrFail();
            if ($assignment->status !== LifecycleStatus::Active || $node->status !== LifecycleStatus::Active || $node->platform !== 'linux'
                || filter_var($node->wireguard_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new ResourceOperationException('metrics.node_inactive', 'Credential reset requires an active Linux Metrics Node.', 409);
            }
            $revision = $this->material->prepare($node);

            return $this->operations->execute('metrics.credentials_reset', 'metrics', $node->id, $node->id,
                ['assignment_id' => $assignment->id, 'reset_revision' => $revision, 'address' => $node->wireguard_ip,
                    'credential' => ['$secret' => 'metrics.credentials_reset', 'id' => $node->id]],
                'operator', null);
        });
    }
}
