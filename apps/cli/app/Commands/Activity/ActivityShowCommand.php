<?php

declare(strict_types=1);

namespace App\Commands\Activity;

use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class ActivityShowCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'activity:show {id : Activity ID} {--json}';

    #[\Override]
    protected $description = 'Show one gateway activity history entry.';

    public function handle(): int
    {
        $id = rawurlencode((string) $this->argument('id'));

        if ($id === '') {
            return $this->renderFailure('validation_failed', 'The id argument is required.');
        }

        try {
            $response = $this->gatewayGet("/api/activity/{$id}");
        } catch (GatewayApiException $exception) {
            return $this->renderFailure($exception->cliFailureCode(), $exception->getMessage());
        }

        return $this->renderSuccess($response);
    }
}
