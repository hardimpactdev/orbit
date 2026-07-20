<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Commands\Concerns\WithStepTree;
use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\confirm;

final class AppAgentIdeCommand extends AppGatewayCommand
{
    use WithStepTree;

    #[\Override]
    protected $signature = 'instance:agent-ide
        {instance? : Instance selector (project.instance or hostname)}
        {agent_ide? : Agent IDE adapter (opencode, polyscope, inherit, or none)}
        {--force : Confirm destructive workspace cleanup without prompting}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Set the default agent IDE for an instance.';

    public function handle(): int
    {
        $selector = $this->stringArgument('instance');
        $agentIde = $this->stringArgument('agent_ide');

        if ($selector === null) {
            return $this->failValidation('instance', 'Instance is required.');
        }

        if ($agentIde === null) {
            return $this->failValidation('agent_ide', 'Agent IDE adapter is required.');
        }

        return $this->setAgentIde($selector, $agentIde, $this->option('force') === true);
    }

    private function setAgentIde(string $selector, string $agentIde, bool $force): int
    {
        try {
            $response = $this->postAgentIde($selector, $agentIde, $force);
        } catch (GatewayApiException $exception) {
            if (! $this->shouldPromptForCleanupConsent($exception, $force)) {
                return $this->renderGatewayFailure($exception);
            }

            if (! $this->confirmWorkspaceCleanup($exception)) {
                return $this->renderFailure('validation_failed', 'Operation cancelled.');
            }

            return $this->setAgentIde($selector, $agentIde, true);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        return $this->renderAgentIdeTree($selector, $response);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderAgentIdeTree(string $selector, array $response): int
    {
        $outcome = $this->runStepOperation(
            'Configuring Instance Agent IDE',
            $this->phases($response),
            work: static fn (): bool => true,
            doneFooter: "Instance '{$selector}' agent IDE "
            .($this->action($response) === 'converged' ? 'unchanged' : 'configured'),
        );

        if (! $outcome->isCompleted()) {
            return self::FAILURE;
        }

        $this->renderAgentIdeNotes($response);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array{label: string, doneLabel: string}>
     */
    private function phases(array $response): array
    {
        $phases = [
            ['label' => 'Validate adapter', 'doneLabel' => 'Validated adapter'],
            ['label' => 'Check for workspace cleanup', 'doneLabel' => 'Checked for workspace cleanup'],
        ];

        foreach ($this->workspacesRemoved($response) as $name) {
            $phases[] = [
                'label' => "Remove stale workspace `{$name}`",
                'doneLabel' => "Removed stale workspace `{$name}`",
            ];
        }

        $phases[] = [
            'label' => 'Apply and verify instance agent IDE',
            'doneLabel' => 'Applied and verified instance agent IDE',
        ];

        return $phases;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderAgentIdeNotes(array $response): void
    {
        $this->line('  '.$this->successLine($response));

        $removed = $this->workspacesRemoved($response);

        if ($removed !== []) {
            $this->line('  Removed '.count($removed).' stale workspaces during adapter switch:');

            foreach ($removed as $name) {
                $this->line("  - {$name}");
            }
        }

        foreach ($this->warningMessages($response) as $message) {
            $this->line("  {$message}");
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function successLine(array $response): string
    {
        $data = $this->successData($response);
        $instance = is_array($data['instance'] ?? null) ? $data['instance'] : [];
        $project = (string) ($instance['project'] ?? '');
        $name = (string) ($instance['name'] ?? '');
        $selector = $project !== '' && $name !== '' ? "{$project}.{$name}" : $name;
        $node = (string) ($instance['node'] ?? '');

        $agentIde = is_array($data['agent_ide'] ?? null) ? $data['agent_ide'] : [];
        $adapter = is_string($agentIde['adapter'] ?? null) && $agentIde['adapter'] !== '' ? $agentIde['adapter'] : null;
        $effective = is_string($agentIde['effective_adapter'] ?? null) && $agentIde['effective_adapter'] !== ''
            ? $agentIde['effective_adapter']
            : null;

        if ($this->action($response) === 'converged') {
            $set = $adapter ?? 'none';

            return "Instance \"{$selector}\" agent IDE already set to \"{$set}\".";
        }

        if ($adapter !== null) {
            $effectiveLabel = $effective ?? 'none';

            return "Instance \"{$selector}\" agent IDE set to \"{$adapter}\" (effective: \"{$effectiveLabel}\").";
        }

        if (($agentIde['source'] ?? null) === 'instance') {
            return "Instance \"{$selector}\" agent IDE set to none (effective: none).";
        }

        if ($effective === null) {
            return "Instance \"{$selector}\" agent IDE set to inherit (effective: none).";
        }

        if ($node !== '') {
            return "Instance \"{$selector}\" agent IDE set to inherit (effective: \"{$effective}\" from node \"{$node}\").";
        }

        return "Instance \"{$selector}\" agent IDE set to inherit (effective: \"{$effective}\").";
    }

    private function shouldPromptForCleanupConsent(GatewayApiException $exception, bool $force): bool
    {
        return (
            ! $force
            && ! $this->wantsJson()
            && $this->input->isInteractive()
            && $exception->gatewayErrorCode() === 'workspace_cleanup_consent_required'
        );
    }

    private function confirmWorkspaceCleanup(GatewayApiException $exception): bool
    {
        $meta = $exception->gatewayErrorMeta();
        $previousAdapter = is_string($meta['previous_adapter'] ?? null)
            ? $meta['previous_adapter']
            : 'previous adapter';
        $staleWorkspaces = is_array($meta['stale_workspaces'] ?? null) ? $meta['stale_workspaces'] : [];
        $count = count($staleWorkspaces);

        return confirm(
            label: "This will remove {$count} workspace(s) managed by the previous adapter '{$previousAdapter}'. Continue?",
            default: false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function postAgentIde(string $selector, string $agentIde, bool $force): array
    {
        return $this->gatewayPost($this->apiInstancePath($selector, '/agent-ide'), [
            'agent_ide' => $agentIde,
            'force' => $force,
        ]);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function action(array $response): string
    {
        $action = $this->successData($response)['action'] ?? null;

        return is_string($action) ? $action : '';
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<string>
     */
    private function workspacesRemoved(array $response): array
    {
        $cleanup = $this->successData($response)['cleanup'] ?? null;
        $removed = is_array($cleanup) ? $cleanup['workspaces_removed'] ?? null : null;

        if (! is_array($removed)) {
            return [];
        }

        $names = [];

        foreach ($removed as $name) {
            if (is_string($name) && trim($name) !== '') {
                $names[] = trim($name);
            }
        }

        return $names;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<string>
     */
    private function warningMessages(array $response): array
    {
        $warnings = $response['success']['meta']['warnings'] ?? null;

        if (! is_array($warnings)) {
            return [];
        }

        $messages = [];

        foreach ($warnings as $entry) {
            if (is_array($entry) && is_string($entry['message'] ?? null) && trim($entry['message']) !== '') {
                $messages[] = trim($entry['message']);
            }
        }

        return $messages;
    }
}
