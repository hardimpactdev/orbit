<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Instances\DisableInstanceAutoDeployRequest;
use Orbit\Sdk\Responses\Instances\InstanceResponse;

final class DisableInstanceAutoDeployCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'instance:auto-deploy:disable
        {instance : Numeric instance ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Stop deploying new commits of the branch automatically.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $instanceId = $this->positiveId('instance', 'Instance', 'instance.id_invalid');

        if ($instanceId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $instance = $this->sendWithProgress(
            $connector,
            new DisableInstanceAutoDeployRequest($instanceId),
            InstanceResponse::class,
            ['Disable automatic deployment', 'Disabling automatic deployment', 'Disabled automatic deployment'],
        );

        if (! $instance instanceof InstanceResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($instance->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Instance: {$instance->name}", [
            'ID' => $instance->id,
            ...InstanceAutoDeployFields::of($instance),
            'Request ID' => $instance->requestId,
        ]));

        return self::SUCCESS;
    }
}
