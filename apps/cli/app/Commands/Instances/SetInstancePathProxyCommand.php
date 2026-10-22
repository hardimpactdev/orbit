<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Instances\SetInstancePathProxyRequest;
use Orbit\Sdk\Responses\Instances\InstancePathProxyResponse;

final class SetInstancePathProxyCommand extends InstancePathProxyCommand
{
    #[\Override]
    protected $signature = 'instance:path-proxy:set
        {--instance= : Positive Instance ID or exact Route domain}
        {--process= : Upstream Node-owned Docker Process ID}
        {--port= : TCP port published on the upstream Node WireGuard address}
        {--prefix=/powersync : Reserved top-level path prefix}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Publish a Process under a prefix on an existing public app Route.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $instance = $this->proxyInput('instance', 'Instance ID or Route domain');
        if ($instance === null) {
            return self::FAILURE;
        }
        $process = $this->proxyInput('process', 'Process ID');
        if ($process === null) {
            return self::FAILURE;
        }
        $port = $this->proxyInput('port', 'Published TCP port');
        if ($port === null) {
            return self::FAILURE;
        }
        $processId = filter_var($process, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $publishedPort = filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if (! is_int($processId) || ! is_int($publishedPort)) {
            $this->renderGatewayFailure('path_proxy.input_invalid', 'Process ID must be positive and the published TCP port must be between 1 and 65535.');

            return self::FAILURE;
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        $proxy = $this->sendWithProgress($connector,
            new SetInstancePathProxyRequest($instance, $processId, $publishedPort, $this->stringOption('prefix')),
            InstancePathProxyResponse::class, ['Set path proxy', 'Setting path proxy', 'Set path proxy']);

        return $proxy instanceof InstancePathProxyResponse ? $this->renderProxy($proxy, 'Path proxy published.') : self::FAILURE;
    }
}
