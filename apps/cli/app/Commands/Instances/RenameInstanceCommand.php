<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Instances\RenameInstanceRequest;
use Orbit\Sdk\Responses\Instances\InstanceResponse;

final class RenameInstanceCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'instance:rename
        {instance : Numeric Instance ID}
        {--branch= : Branch already checked out on the Node}
        {--domain= : Always refused with operation.kind_unavailable; rename does not move the Route}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Record an already checked-out development branch.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $id = $this->positiveId('instance', 'Instance', 'instance.id_invalid');
        if ($id === null) {
            return self::FAILURE;
        }
        if ($this->option('branch') === '' || $this->option('domain') === '') {
            return $this->renderGatewayFailure('validation.failed', 'Branch and domain must be nonempty when supplied.');
        }
        $branch = $this->stringOption('branch');
        $domain = $this->stringOption('domain');
        if ($branch === null && $domain === null) {
            return $this->renderGatewayFailure('validation.failed', 'Provide --branch or --domain.');
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        $instance = $this->sendWithProgress($connector, new RenameInstanceRequest($id, $branch, $domain), InstanceResponse::class,
            ['Record the branch', 'Recording the branch', 'Recorded the branch']);
        if (! $instance instanceof InstanceResponse) {
            return self::FAILURE;
        }
        if ($this->option('json') === true) {
            $this->writeJson($instance->toArray());

            return self::SUCCESS;
        }
        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Instance: {$instance->name}", [
            'ID' => $instance->id,
            'Selected branch' => $instance->selectedBranch,
            'Request ID' => $instance->requestId,
        ]));

        return self::SUCCESS;
    }
}
