<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;

final class AppDevelopmentSetupStepUpdateCommand extends AppGatewayCommand
{
    protected $signature = 'app-development-setup-step:update {app : App name} {step : Step id} {--command=} {--timeout=} {--before=} {--after=} {--json}';
    protected $description = 'Update an app development setup default.';

    public function handle(): int
    {
        try {
            $r = $this->gatewayPatch(
                $this->apiProjectPath(
                    (string) $this->argument('app'),
                    '/development-setup-steps/'.(int) $this->argument('step'),
                ),
                $this->filledQuery([
                    'command' => $this->option('command'),
                    'timeout' => $this->option('timeout'),
                    'before' => $this->option('before'),
                    'after' => $this->option('after'),
                ]),
            );
        } catch (GatewayApiException $e) {
            return $this->renderGatewayFailure($e);
        }

        return $this->wantsJson()
            ? $this->renderSuccess($r)
            : $this->line('App development setup default updated.') ?? self::SUCCESS;
    }
}
