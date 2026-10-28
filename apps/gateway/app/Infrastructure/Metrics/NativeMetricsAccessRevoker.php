<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Metrics\MetricsAccessRevoker;
use App\Domain\Metrics\MetricsGatewayResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\AwaitOperation;
use App\Models\NodeRole;

final readonly class NativeMetricsAccessRevoker implements MetricsAccessRevoker
{
    public function __construct(private StartOperationAction $operations, private AwaitOperation $await, private MetricsGatewayResolver $gateways) {}

    public function revoke(): void
    {
        if (! NodeRole::query()->where('role', RoleName::Metrics)->exists()) {
            return;
        }
        $gateway = $this->gateways->resolve();
        $this->await->execute($this->operations->execute('route.caddy_converge', 'node', $gateway->id, $gateway->id, ['reason' => 'metrics access revoked'], 'integration', null));
    }
}
