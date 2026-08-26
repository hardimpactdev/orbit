<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;

final class AppDevelopmentSetupStepListCommand extends AppGatewayCommand
{
    protected $signature = 'app-development-setup-step:list {app : App name} {--json}';
    protected $description = 'List app development setup defaults.';

    public function handle(): int
    {
        try {
            $r = $this->gatewayGet($this->apiProjectPath((string) $this->argument('app'), '/development-setup-steps'));
        } catch (GatewayApiException $e) {
            return $this->renderGatewayFailure($e);
        }

        return $this->wantsJson()
            ? $this->renderSuccess($r)
            : $this->line(json_encode($this->successData($r)['steps'] ?? [])) ?? self::SUCCESS;
    }
}
