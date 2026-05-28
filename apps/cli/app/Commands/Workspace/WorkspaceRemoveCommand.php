<?php

declare(strict_types=1);

namespace App\Commands\Workspace;

use App\Exceptions\GatewayApiException;

final class WorkspaceRemoveCommand extends WorkspaceGatewayCommand
{
    protected $signature = 'workspace:remove
        {name? : Workspace name}
        {--app= : Parent app slug}
        {--keep-files : Preserve workspace files on the app node}
        {--force : Confirm destructive operation without prompting}
        {--json : Output JSON}';

    protected $description = 'Remove a workspace and its owned artifacts.';

    public function handle(): int
    {
        $name = $this->stringArgument('name');

        if ($name === null) {
            return $this->failValidation('name', 'Workspace name is required.');
        }

        if ($this->option('force') !== true) {
            return $this->failValidation('force', 'Use --force to remove this workspace.');
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
}
