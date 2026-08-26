<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;

final class AppDevelopmentSetupStepUpdateCommand extends AppGatewayCommand
{
    #[\Override]
    protected $signature = 'app-development-setup-step:update {app? : App name} {step? : Step id} {--command=} {--timeout=} {--before=} {--after=} {--json : Output JSON}';
    protected $description = 'Update an app development setup default.';

    public function handle(): int
    {
        $app = $this->stringArgument('app') ?? $this->appFromOrbitMarker();
        if ($app === null) {
            return $this->failValidation('app', 'App is required.');
        }
        $step = $this->positiveArgument('step');
        if ($step === null) {
            return $this->failValidation('step', 'Step must be a positive integer.');
        }
        $input = AppDevelopmentSetupStepUpdateInput::fromOptions(
            command: $this->stringOption('command'),
            timeout: $this->option('timeout'),
            before: $this->option('before'),
            after: $this->option('after'),
        );
        if (array_key_exists('error', $input)) {
            return $this->failValidation($input['error']['field'], $input['error']['message']);
        }
        try {
            $response = $this->gatewayPatch(
                $this->apiProjectPath($app, "/development-setup-steps/{$step}"),
                $input['values'],
            );
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }
        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }
        $this->line("✓ Updated development setup default {$step} for app '{$app}'.");

        return self::SUCCESS;
    }

    private function positiveArgument(string $name): ?int
    {
        $value = $this->stringArgument($name);

        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }
}
