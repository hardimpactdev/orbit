<?php

declare(strict_types=1);

namespace App\Commands\Workspace;

use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

final class WorkspaceRemoveCommand extends WorkspaceGatewayCommand
{
    #[\Override]
    protected $signature = 'workspace:remove
        {name? : Workspace name}
        {--app= : Parent app slug}
        {--keep-files : Preserve workspace files on the app node}
        {--force : Confirm destructive operation without prompting}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Remove a workspace and its owned artifacts.';

    public function handle(): int
    {
        $name = $this->resolveName();

        if ($name === null) {
            return $this->failValidation('name', 'Workspace name is required.');
        }

        $confirmation = $this->confirmRemoval($name);

        if ($confirmation !== null) {
            return $confirmation;
        }

        try {
            $response = $this->gatewayDelete($this->pathWithQuery(
                '/api/workspaces/'.rawurlencode($name),
                ['app' => $this->stringOption('app') ?? $this->appFromOrbitMarker()],
            ), [
                'keep_files' => $this->option('keep-files') === true,
                'destructive_consent' => true,
                'destructive_consent_source' => 'force',
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }

    private function resolveName(): ?string
    {
        $name = $this->stringArgument('name');

        if ($name !== null) {
            return $name;
        }

        if (! $this->isInteractiveInput()) {
            return null;
        }

        $name = trim(text(label: 'Workspace name', required: true));

        return $name !== '' ? $name : null;
    }

    private function confirmRemoval(string $name): ?int
    {
        if ($this->option('force') === true) {
            return null;
        }

        if ($this->wantsJson() || ! $this->input->isInteractive()) {
            return $this->failValidation('force', 'Use --force to remove this workspace.');
        }

        if (confirm(label: "Remove workspace '{$name}'?", default: false)) {
            return null;
        }

        return $this->renderFailure('validation_failed', 'Operation cancelled.', ['field' => 'force']);
    }

    private function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }
}
