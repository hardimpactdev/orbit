<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\confirm;

final class InstanceRemoveCommand extends InstanceCommand
{
    #[\Override]
    protected $signature = 'instance:remove
        {instance? : app.instance selector}
        {--force : Confirm destructive remove}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Remove an instance from an app.';

    public function handle(): int
    {
        $selector = $this->resolveInstanceSelector();

        if (is_int($selector)) {
            return $selector;
        }

        if (! (bool) $this->option('force')) {
            if ($this->wantsJson() || ! $this->input->isInteractive()) {
                return $this->failValidation('force', 'Removing an instance requires --force.');
            }

            $confirmed = confirm(
                label: "Remove instance '{$selector['app']}.{$selector['instance']}'? The app and sibling instances will remain.",
                default: false,
            );

            if (! $confirmed) {
                return $this->renderFailure('validation_failed', 'Operation cancelled.', [
                    'field' => 'force',
                    'reason' => 'cancelled',
                ]);
            }
        }

        try {
            $response = $this->gatewayDelete($this->apiProjectPath(
                $selector['app'],
                '/instances/'.rawurlencode($selector['instance']),
            ), [
                'destructive_consent' => true,
                'destructive_consent_source' => 'force',
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $result = $this->successData($response)['result'] ?? null;
        $name = is_array($result) && is_string($result['instance'] ?? null)
            ? $result['instance']
            : $selector['instance'];

        $this->line("Removed instance '{$name}'.");

        return self::SUCCESS;
    }
}
