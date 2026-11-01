<?php

declare(strict_types=1);

namespace App\Commands\Metrics;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Metrics\DisableMetricsRequest;
use Orbit\Sdk\Requests\Metrics\ShowMetricsStatusRequest;
use Orbit\Sdk\Responses\Metrics\MetricsStatusResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class DisableMetricsCommand extends MetricsCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'metrics:disable {--force : Skip confirmation} {--purge-data : Delete Metrics data} {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Disable Metrics.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        $force = $this->option('force') === true;
        $purge = $this->option('purge-data') === true;

        if ($purge && ! $force) {
            return $this->renderGatewayFailure('metrics.force_required', '--purge-data requires --force.');
        }

        if (! $force && ! $this->consoleMode()->mayPrompt) {
            return $this->renderGatewayFailure(
                'metrics.force_required',
                'Non-interactive Metrics disable requires --force.',
            );
        }

        $connector = $this->connector($repository, $factory);
        if ($connector === null) {
            return self::FAILURE;
        }

        if (! $force) {
            $status = $this->sendWithProgress(
                $connector,
                new ShowMetricsStatusRequest,
                MetricsStatusResponse::class,
                ['Resolve Metrics status', 'Loading Metrics status', 'Loaded Metrics status'],
            );
            if (! $status instanceof MetricsStatusResponse) {
                return self::FAILURE;
            }

            if (! $this->confirmAction(
                'Disable Metrics? Data: preserve. Assignment: '.($status->assignment === null ? 'none' : 'remove').'.',
                'Metrics disable cancelled.',
                option: 'force',
                requiredCode: 'metrics.force_required',
                requiredMessage: 'Non-interactive Metrics disable requires --force.',
            )) {
                return self::FAILURE;
            }
        }

        $response = $this->sendWithProgress(
            $connector,
            new DisableMetricsRequest(force: true, purgeData: $purge),
            OperationResponse::class,
            ['Disable Metrics', 'Requesting Metrics removal', 'Metrics removal accepted'],
        );

        if (! $response instanceof OperationResponse) {
            return self::FAILURE;
        }
        $profile = $this->activeGatewayProfile($repository);

        return $profile === null ? self::FAILURE : $this->followOperation($connector, $profile, $response, 'Metrics', 'Role');
    }
}
