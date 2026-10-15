<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use JsonException;
use Orbit\Sdk\Requests\Instances\UpdateInstanceRequest;
use Orbit\Sdk\Responses\Instances\InstanceResponse;

final class UpdateInstanceCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'instance:update
        {instance : Numeric instance ID}
        {--branch= : Deployment branch}
        {--shared-paths= : JSON list of shared directories; [] disables sharing and null restores the default}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Update production Instance deployment settings.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $instanceId = $this->positiveId('instance', 'Instance', 'instance.id_invalid');

        if ($instanceId === null) {
            return self::FAILURE;
        }

        $branch = $this->stringOption('branch');

        $rawPaths = $this->option('shared-paths');
        $sharedPathsProvided = $this->input->hasParameterOption('--shared-paths');
        $sharedPaths = null;

        if ($sharedPathsProvided) {
            try {
                $sharedPaths = is_string($rawPaths) ? json_decode($rawPaths, flags: JSON_THROW_ON_ERROR) : false;
            } catch (JsonException) {
                $sharedPaths = false;
            }
            if ($sharedPaths !== null && (! is_array($sharedPaths) || ! array_is_list($sharedPaths) || ! array_all($sharedPaths, static fn (mixed $path): bool => is_string($path)))) {
                return $this->renderGatewayFailure('instance.shared_paths_invalid', 'Shared paths must be a JSON list of directory paths or null.');
            }
        }

        if ($this->option('branch') === '') {
            return $this->renderGatewayFailure('instance.branch_required', 'Deployment branch must be nonempty.');
        }
        if ($branch === null && ! $sharedPathsProvided) {
            return $this->renderGatewayFailure('instance.update_required', 'Provide --branch or --shared-paths to update deployment settings.');
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $instance = $this->sendWithProgress(
            $connector,
            new UpdateInstanceRequest($instanceId, $branch, $sharedPaths, $sharedPathsProvided),
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

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Instance: {$instance->name}", [
            'ID' => $instance->id,
            'Status' => $instance->status,
            ...($branch === null ? [] : ['Deployment branch' => $branch]),
            ...($sharedPathsProvided ? ['Shared paths' => $instance->sharedPaths === null ? null : ($instance->sharedPaths === [] ? 'None' : implode(', ', $instance->sharedPaths))] : []),
            'Selected branch' => $instance->selectedBranch,
            'Request ID' => $instance->requestId,
        ]));

        return self::SUCCESS;
    }
}
