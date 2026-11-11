<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Instances\ListInstanceNodeServiceTargetsRequest;
use Orbit\Sdk\Responses\Instances\InstanceNodeServiceTargetRecordResponse;
use Orbit\Sdk\Responses\Instances\InstanceNodeServiceTargetsResponse;

final class ListInstanceNodeServiceTargetsCommand extends NodeServiceTargetCommand
{
    #[\Override]
    protected $signature = 'instance:node-service:list
        {instance? : Numeric Instance ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List one Instance\'s recorded Node service attachments.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $instance = $this->instanceId($repository, $connectors);
        if ($instance === null) {
            return self::FAILURE;
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        $response = $this->sendWithProgress($connector, new ListInstanceNodeServiceTargetsRequest($instance), InstanceNodeServiceTargetsResponse::class,
            ['List Node service targets', 'Loading Node service targets', 'Loaded Node service targets']);
        if (! $response instanceof InstanceNodeServiceTargetsResponse) {
            return self::FAILURE;
        }
        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());
        } else {
            $rows = array_map(static fn (InstanceNodeServiceTargetRecordResponse $target): array => [$target->id, $target->processId,
                $target->protocol, $target->port, $target->hostEnvKey, $target->portEnvKey], $response->targets);
            ConsoleWriter::write($this->output, $this->humanRenderer()->table(['ID', 'Process', 'Protocol', 'Port', 'Host key', 'Port key'], $rows, 'No Node service targets attached.'));
            $this->writeHumanMessage('Request ID: '.$response->requestId);
        }

        return self::SUCCESS;
    }
}
