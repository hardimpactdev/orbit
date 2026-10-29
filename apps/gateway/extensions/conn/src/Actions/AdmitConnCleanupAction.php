<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Actions;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Integrations\IntegrationAvailability;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\Conn\Models\ConnEnvironment;

final readonly class AdmitConnCleanupAction
{
    public function __construct(private StartOperationAction $operations, private NodeAccessAuthorizer $nodes) {}

    public function execute(ConnEnvironment $environment, Node $caller): ?Operation
    {
        IntegrationAvailability::assertKind('conn.gateway_sessions_revoke');
        $gateway = $this->nodes->activeGatewayId() ?? throw new ResourceOperationException('conn.gateway_missing', 'Cleanup needs one active Gateway Node.', 409);

        return DB::transaction(function () use ($environment, $caller, $gateway): ?Operation {
            $environment = ConnEnvironment::query()->lockForUpdate()->findOrFail($environment->id);
            if ($environment->cleanup_version <= $environment->cleanup_completed_version) {
                return null;
            }

            return $this->operations->execute('conn.gateway_sessions_revoke', 'conn_environment', $environment->id, $gateway,
                ['conn_environment_id' => $environment->id, 'environment' => ['$secret' => 'conn.environment', 'id' => $environment->id],
                    'cleanup_version' => $environment->cleanup_version, 'caller_node_id' => $caller->id], 'operator', null);
        });
    }
}
