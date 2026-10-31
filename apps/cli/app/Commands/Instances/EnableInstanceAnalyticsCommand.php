<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Analytics\EnableInstanceAnalyticsRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class EnableInstanceAnalyticsCommand extends InstanceAnalyticsCommand
{
    #[\Override]
    protected $signature = 'instance:analytics:enable
        {instance : Numeric instance ID}
        {--host=* : Tracking host; repeat for more. Defaults to analytics.<instance domain>}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Publish the analytics tracking hosts of an Instance.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $instanceId = $this->positiveId('instance', 'Instance', 'instance.id_invalid');

        if ($instanceId === null) {
            return self::FAILURE;
        }

        $hosts = array_values(array_filter(
            (array) $this->option('host'),
            static fn (mixed $host): bool => is_string($host) && $host !== '',
        ));

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        // The Gateway sets the exact host set: it adds the missing hosts and removes the others.
        $response = $this->sendWithProgress(
            $connector,
            new EnableInstanceAnalyticsRequest($instanceId, $hosts),
            OperationResponse::class,
            ['Enable analytics', 'Requesting analytics enable', 'Analytics request accepted'],
        );

        return $response instanceof OperationResponse
            ? $this->finishOperation($connector, $repository, $response, $instanceId, 'Analytics tracking enabled.')
            : self::FAILURE;
    }
}
