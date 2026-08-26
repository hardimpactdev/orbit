<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\confirm;

final class AppDevelopmentSetupStepRemoveCommand extends AppGatewayCommand
{
    #[\Override]
    protected $signature = 'app-development-setup-step:remove {app? : App name} {step? : Step id} {--force : Skip confirmation} {--json : Output JSON}';
    protected $description = 'Remove an app development setup default.';

    public function handle(): int
    {
        $app = $this->stringArgument('app') ?? $this->appFromOrbitMarker();
        if ($app === null)
            return $this->failValidation('app', 'App is required.');
        $step = $this->stringArgument('step');
        if (! is_string($step) || ! ctype_digit($step) || (int) $step < 1)
            return $this->failValidation('step', 'Step must be a positive integer.');
        $source = 'prompt';
        if ($this->option('force'))
            $source = 'force';
        elseif (
            ! $this->wantsJson()
            && $this->input->isInteractive()
            && confirm(label: 'Remove this app development setup default?', default: false)
        ) {
            $source = 'prompt';
        } else
            return $this->failValidation(
                'force',
                'This is a destructive operation. Use --force or confirm the prompt.',
            );
        try {
            $response = $this->gatewayDelete($this->apiProjectPath($app, "/development-setup-steps/{$step}"), [
                'destructive_consent' => true,
                'destructive_consent_source' => $source,
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }
        if ($this->wantsJson())
            return $this->renderSuccess($response);
        $this->line("✓ Removed development setup default {$step} from app '{$app}'.");

        return self::SUCCESS;
    }
}
