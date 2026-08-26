<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;

final class AppDevelopmentSetupStepRemoveCommand extends AppGatewayCommand
{
    protected $signature = 'app-development-setup-step:remove {app : App name} {step : Step id} {--force} {--json}';
    protected $description = 'Remove an app development setup default.';

    public function handle(): int
    {
        if (! $this->option('force'))
            return $this->failValidation('force', 'Use --force to remove this setup default.');
        try {
            $r = $this->gatewayDelete(
                $this->apiProjectPath(
                    (string) $this->argument('app'),
                    '/development-setup-steps/'.(int) $this->argument('step'),
                ),
                ['destructive_consent' => true],
            );
        } catch (GatewayApiException $e) {
            return $this->renderGatewayFailure($e);
        }

        return $this->wantsJson()
            ? $this->renderSuccess($r)
            : $this->line('App development setup default removed.') ?? self::SUCCESS;
    }
}
