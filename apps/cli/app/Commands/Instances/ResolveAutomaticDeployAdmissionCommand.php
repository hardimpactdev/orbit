<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Deployments\ResolveAutomaticDeployAdmissionRequest;
use Orbit\Sdk\Responses\Deployments\AutomaticDeployReceiptResponse;

final class ResolveAutomaticDeployAdmissionCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'instance:deploy:automatic:resolve
        {instance : Numeric instance ID}
        {request_key : Original automatic request key}
        {commit : Original full commit SHA}
        {--caller-node= : Original caller Node ID when resolving for another peer}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Resolve an automatic admission and fence its key if nothing was admitted.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $instance = $this->positiveId('instance', 'Instance', 'instance.id_invalid');
        $key = $this->stringArgument('request_key', 'Request key', 'operation.request_key_required');
        $commit = $this->stringArgument('commit', 'Commit', 'deployment.commit_required');
        $caller = $this->stringOption('caller-node');
        if ($instance === null || $key === null || $commit === null) {
            return self::FAILURE;
        }
        if (($this->input->hasParameterOption('--caller-node') && $caller === null) || ($caller !== null && (preg_match('/\A[1-9][0-9]*\z/D', $caller) !== 1 || filter_var($caller, FILTER_VALIDATE_INT) === false))) {
            return $this->renderGatewayFailure('node.id_invalid', 'Original caller Node ID must be a positive integer.');
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        $receipt = $this->sendWithProgress(
            $connector, new ResolveAutomaticDeployAdmissionRequest($instance, $key, $commit, $caller === null ? null : (int) $caller),
            AutomaticDeployReceiptResponse::class, ['Resolve admission', 'Resolving admission', 'Resolved admission'],
        );
        if (! $receipt instanceof AutomaticDeployReceiptResponse) {
            return self::FAILURE;
        }
        if ($this->option('json') === true) {
            $this->writeJson($receipt->toArray());

            return self::SUCCESS;
        }
        $operation = $receipt->operation;
        $this->writeHumanMessage($operation === null ? 'No admission receipt.' : "Operation {$operation->id}: {$operation->status}".($operation->errorCode === null ? '' : " ({$operation->errorCode})"));
        $this->writeHumanMessage('Request ID: '.$receipt->requestId);

        return self::SUCCESS;
    }
}
