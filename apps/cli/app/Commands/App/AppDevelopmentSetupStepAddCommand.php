<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;

final class AppDevelopmentSetupStepAddCommand extends AppGatewayCommand
{
    protected $signature = 'app-development-setup-step:add {app : App name} {--command=} {--before=} {--after=} {--timeout=600} {--json}';
    protected $description = 'Add an app development setup default.';

    public function handle(): int
    {
        $command = $this->stringOption('command');
        if ($command === null)
            return $this->failValidation('command', 'Command is required.');
        try {
            $r = $this->gatewayPost(
                $this->apiProjectPath((string) $this->argument('app'), '/development-setup-steps'),
                $this->filledQuery([
                    'command' => $command,
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
            : $this->line('App development setup default added.') ?? self::SUCCESS;
    }
}
