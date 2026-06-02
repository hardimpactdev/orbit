<?php

declare(strict_types=1);

namespace App\Commands\Operation;

use App\Commands\Concerns\StreamsGatewayProgress;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Services\Updates\LocalUpdateResult;
use App\Services\Updates\LocalUpdateWorkflow;
use Orbit\Core\Progress\ProgressEventType;

final class UpdateAllCommand extends GatewayCommand
{
    use StreamsGatewayProgress;

    #[\Override]
    protected $signature = 'update:all {--json : Output JSON}';

    #[\Override]
    protected $description = 'Update every managed Orbit installation through the gateway.';

    public function handle(LocalUpdateWorkflow $localUpdates): int
    {
        $localResult = $localUpdates->run();

        if (! $localResult->successful()) {
            return $this->renderLocalUpdateFailure($localResult);
        }

        try {
            $response = $this->gatewayPost('/api/update/all/start');
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        $eventsUrl = $this->eventsUrl($response);

        if ($eventsUrl === null) {
            return $this->renderFailure(
                'gateway_unavailable',
                'Gateway update operation did not provide an event stream URL.',
            );
        }

        return $this->followOperationProgress(
            $eventsUrl,
            fn (ProgressEventType $type, array $payload): int => $this->renderProgressTerminalFrame($type, $payload),
        );
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function eventsUrl(array $response): ?string
    {
        $success = $response['success'] ?? null;
        $data = is_array($success) ? ($success['data'] ?? null) : null;
        $eventsUrl = is_array($data) ? ($data['events_url'] ?? null) : null;

        if (! is_string($eventsUrl)) {
            return null;
        }

        $eventsUrl = trim($eventsUrl);

        return $eventsUrl === '' ? null : $eventsUrl;
    }

    private function renderLocalUpdateFailure(LocalUpdateResult $result): int
    {
        if ($result->status === LocalUpdateResult::STATUS_CHECKOUT_UNAVAILABLE) {
            return $this->renderFailure(
                'local_checkout_unavailable',
                'Local Orbit checkout cannot be updated.',
                ['path' => $result->checkoutPath ?? ''],
            );
        }

        $data = $result->output !== '' ? ['output' => $result->output] : [];

        return $this->renderFailure(
            'local_update_failed',
            'Failed to update local Orbit checkout.',
            ['failed_step' => $result->failedStep ?? 'unknown'],
            $data,
        );
    }
}
