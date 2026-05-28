<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;

final class AppWorkerCommand extends AppGatewayCommand
{
    #[\Override]
    protected $signature = 'app:worker
        {action? : Action to perform (show|enable|disable)}
        {app? : App name or hostname}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Inspect or change FrankenPHP worker mode for an app.';

    public function handle(): int
    {
        $action = $this->stringArgument('action');
        $selector = $this->stringArgument('app');

        if (! in_array($action, ['show', 'enable', 'disable'], true)) {
            return $this->renderFailure(
                'validation_failed',
                'Action must be one of: show, enable, disable.',
                ['field' => 'action', 'allowed' => ['show', 'enable', 'disable']],
            );
        }

        if ($selector === null) {
            return $this->failValidation('app', 'App is required.');
        }

        try {
            $response = $action === 'show'
                ? $this->gatewayGet($this->apiAppPath($selector, '/worker'))
                : $this->gatewayPost($this->apiAppPath($selector, "/worker/{$action}"));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
