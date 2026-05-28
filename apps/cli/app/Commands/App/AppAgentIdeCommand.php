<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\confirm;

final class AppAgentIdeCommand extends AppGatewayCommand
{
    #[\Override]
    protected $signature = 'app:agent-ide
        {app? : App name or hostname}
        {agent_ide? : Agent IDE adapter (opencode, polyscope, inherit, or none)}
        {--force : Confirm destructive workspace cleanup without prompting}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Set the default agent IDE for an app.';

    public function handle(): int
    {
        $selector = $this->stringArgument('app');
        $agentIde = $this->stringArgument('agent_ide');

        if ($selector === null) {
            return $this->failValidation('app', 'App is required.');
        }

        if ($agentIde === null) {
            return $this->failValidation('agent_ide', 'Agent IDE adapter is required.');
        }

        return $this->setAgentIde($selector, $agentIde, $this->option('force') === true);
    }

    private function setAgentIde(string $selector, string $agentIde, bool $force): int
    {
        try {
            $response = $this->gatewayPost($this->apiAppPath($selector, '/agent-ide'), [
                'agent_ide' => $agentIde,
                'force' => $force,
            ]);
        } catch (GatewayApiException $exception) {
            if (! $this->shouldPromptForCleanupConsent($exception, $force)) {
                return $this->renderGatewayFailure($exception);
            }

            if (! $this->confirmWorkspaceCleanup($exception)) {
                return $this->renderFailure('validation_failed', 'Operation cancelled.');
            }

            return $this->setAgentIde($selector, $agentIde, true);
        }

        return $this->renderSuccess($response);
    }

    private function shouldPromptForCleanupConsent(GatewayApiException $exception, bool $force): bool
    {
        return ! $force
            && ! $this->wantsJson()
            && $this->input->isInteractive()
            && $exception->gatewayErrorCode() === 'workspace_cleanup_consent_required';
    }

    private function confirmWorkspaceCleanup(GatewayApiException $exception): bool
    {
        $meta = $exception->gatewayErrorMeta();
        $previousAdapter = is_string($meta['previous_adapter'] ?? null)
            ? $meta['previous_adapter']
            : 'previous adapter';
        $staleWorkspaces = is_array($meta['stale_workspaces'] ?? null)
            ? $meta['stale_workspaces']
            : [];
        $count = count($staleWorkspaces);

        return confirm(
            label: "This will remove {$count} workspace(s) managed by the previous adapter '{$previousAdapter}'. Continue?",
            default: false,
        );
    }
}
