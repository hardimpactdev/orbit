<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Analytics\DisableInstanceAnalyticsRequest;

final class DisableInstanceAnalyticsCommand extends InstanceAnalyticsCommand
{
    #[\Override]
    protected $signature = 'instance:analytics:disable
        {instance : Numeric instance ID}
        {--yes : Confirm removing every tracking host}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove the analytics tracking hosts of an Instance.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $instanceId = $this->positiveId('instance', 'Instance', 'instance.id_invalid');

        if ($instanceId === null) {
            return self::FAILURE;
        }

        // Visits stop being counted the moment the hosts are gone, so this asks first.
        if (! $this->confirmAction(
            "Remove every analytics tracking host of Instance #{$instanceId}?",
            'Analytics tracking was not disabled.',
        )) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        return $this->integrationOperation($connector, $repository, new DisableInstanceAnalyticsRequest($instanceId, idempotencyKey: is_string($this->option('idempotency-key')) ? $this->option('idempotency-key') : null), 'Disable Instance analytics');
    }
}
