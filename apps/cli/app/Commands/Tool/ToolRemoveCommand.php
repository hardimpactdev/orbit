<?php

declare(strict_types=1);

namespace App\Commands\Tool;

use App\Exceptions\GatewayApiException;

final class ToolRemoveCommand extends ToolGatewayCommand
{
    #[\Override]
    protected $signature = 'tool:remove
        {tool? : Tool catalog name to remove}
        {--app= : Resolve target by app selector}
        {--node= : Resolve target by node}
        {--force : Confirm destructive removal}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Remove a managed tool through the gateway.';

    public function handle(): int
    {
        $tool = $this->requireToolArgument();

        if (is_int($tool)) {
            return $tool;
        }

        $payload = $this->toolTargetPayload(requireTarget: true);

        if (is_int($payload)) {
            return $payload;
        }

        if (! $this->option('force') && ! $this->wantsJson()) {
            if (! $this->input->isInteractive()) {
                return $this->failValidation('force', 'Use --force or --json to remove this tool.', [
                    'reason' => 'destructive_consent_required',
                ]);
            }

            if (! $this->confirm("Remove tool '{$tool}'?", default: false)) {
                return $this->failValidation('force', 'Operation cancelled.', [
                    'reason' => 'cancelled',
                ]);
            }
        }

        try {
            $response = $this->gatewayDelete('/api/tools/'.rawurlencode($tool), [
                ...$payload,
                'destructive_consent' => true,
                'destructive_consent_source' => $this->destructiveConsentSource(),
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }

    private function destructiveConsentSource(): string
    {
        if ($this->option('force')) {
            return 'force';
        }

        if ($this->wantsJson()) {
            return 'json';
        }

        return 'interactive_confirm';
    }
}
