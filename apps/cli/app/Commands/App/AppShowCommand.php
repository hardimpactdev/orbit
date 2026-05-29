<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class AppShowCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'app:show {app? : App name or hostname to inspect} {--json}';

    #[\Override]
    protected $description = 'Show one app from the gateway registry.';

    public function handle(): int
    {
        $selector = $this->argument('app');

        if (! is_string($selector) || $selector === '') {
            return $this->renderFailure('validation_failed', 'The app argument is required.', ['field' => 'app']);
        }

        $app = rawurlencode($selector);

        try {
            $response = $this->gatewayGet("/api/apps/{$app}");
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
