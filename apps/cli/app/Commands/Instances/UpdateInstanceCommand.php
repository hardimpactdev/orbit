<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Instances\UpdateInstanceRequest;
use Orbit\Sdk\Responses\Instances\InstanceResponse;

final class UpdateInstanceCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'instance:update
        {instance : Numeric instance ID}
        {--branch= : Deployment branch}
        {--auto-deploy= : Deploy each new green commit of the branch automatically (true or false)}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Update a production Instance deployment branch or automatic deployment.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $instanceId = $this->positiveId('instance', 'Instance', 'instance.id_invalid');

        if ($instanceId === null) {
            return self::FAILURE;
        }

        $branch = $this->stringOption('branch');
        $autoDeployOption = $this->option('auto-deploy');
        $autoDeployProvided = $this->input->hasParameterOption('--auto-deploy');

        if ($autoDeployProvided && $autoDeployOption !== 'true' && $autoDeployOption !== 'false') {
            return $this->renderGatewayFailure('instance.auto_deploy_invalid', 'Auto deploy must be true or false.');
        }

        $autoDeploy = $autoDeployProvided ? $autoDeployOption === 'true' : null;

        if ($branch === null && $autoDeploy === null) {
            return $this->renderGatewayFailure(
                'instance.update_required',
                'Provide --branch, --auto-deploy, or both.',
            );
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $instance = $this->sendWithProgress(
            $connector,
            new UpdateInstanceRequest($instanceId, $branch, $autoDeploy),
            InstanceResponse::class,
            ['Update Instance', 'Updating Instance', 'Updated Instance'],
        );

        if (! $instance instanceof InstanceResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($instance->toArray());

            return self::SUCCESS;
        }

        $fields = [
            'ID' => $instance->id,
            'Status' => $instance->status,
        ];

        if ($branch !== null) {
            $fields['Deployment branch'] = $branch;
        }

        $fields['Selected branch'] = $instance->selectedBranch;

        if ($autoDeploy !== null) {
            $fields += InstanceAutoDeployFields::of($instance);
        }

        $fields['Request ID'] = $instance->requestId;
        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Instance: {$instance->name}", $fields));

        $warning = $instance->autoDeploy?->warning;

        if ($autoDeploy === true && $warning !== null) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }
}
