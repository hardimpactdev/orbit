<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Instances\UnsetInstancePathProxyRequest;
use Orbit\Sdk\Responses\Instances\RemovedInstancePathProxyResponse;

final class UnsetInstancePathProxyCommand extends InstancePathProxyCommand
{
    #[\Override]
    protected $signature = 'instance:path-proxy:unset
        {--instance= : Positive Instance ID or exact Route domain}
        {--force : Skip the destructive confirmation prompt}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove an Instance path proxy and keep its Route and Process.';

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
        if (! $this->confirmAction(
            "Remove the path proxy from Instance [{$instance}]? Its public endpoint stops serving the Process.",
            'Path proxy removal cancelled.', option: 'force', requiredCode: 'path_proxy.confirmation_required',
            requiredMessage: 'Use --force to confirm path proxy removal.',
        )) {
            return self::FAILURE;
        }
        $result = $this->sendWithProgress($connector, new UnsetInstancePathProxyRequest($instance), RemovedInstancePathProxyResponse::class,
            ['Remove path proxy', 'Removing path proxy', 'Removed path proxy']);
        if (! $result instanceof RemovedInstancePathProxyResponse) {
            return self::FAILURE;
        }
        if ($this->option('json') === true) {
            $this->writeJson($result->toArray());
        } else {
            ConsoleWriter::write($this->output, $this->humanRenderer()->detail('Path proxy removed.', [
                'Instance ID' => $result->instanceId, 'Request ID' => $result->requestId,
            ]));
        }

        return self::SUCCESS;
    }
}
