<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Instances\ShowInstancePathProxyRequest;
use Orbit\Sdk\Responses\Instances\InstancePathProxyResponse;

final class ShowInstancePathProxyCommand extends InstancePathProxyCommand
{
    #[\Override]
    protected $signature = 'instance:path-proxy:show
        {--instance= : Positive Instance ID or exact Route domain}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show the stored path proxy for an Instance.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $instance = $this->proxyInput('instance', 'Instance ID or Route domain');
        if ($instance === null) {
            return self::FAILURE;
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        $proxy = $this->sendWithProgress($connector, new ShowInstancePathProxyRequest($instance), InstancePathProxyResponse::class,
            ['Show path proxy', 'Loading path proxy', 'Loaded path proxy']);

        return $proxy instanceof InstancePathProxyResponse ? $this->renderProxy($proxy, 'Instance path proxy.') : self::FAILURE;
    }
}
