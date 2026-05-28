<?php

declare(strict_types=1);

namespace App\Commands\Workspace;

use App\Commands\Concerns\StreamsGatewayProgress;
use Orbit\Core\Progress\ProgressEventType;

final class WorkspaceSetupCommand extends WorkspaceGatewayCommand
{
    use StreamsGatewayProgress;

    #[\Override]
    protected $signature = 'workspace:setup
        {name? : Workspace name}
        {--app= : Parent app name}
        {--path= : Explicit workspace path to adopt}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Converge a workspace to a ready-to-develop-in state.';

    public function handle(): int
    {
        $path = $this->stringOption('path');

        if ($path !== null && ! str_starts_with($path, '/')) {
            return $this->failValidation('path', 'Path must be absolute.');
        }

        return $this->streamProgress('/api/workspaces/setup', [
            'name' => $this->stringArgument('name'),
            'app' => $this->stringOption('app') ?? $this->appFromOrbitMarker(),
            'path' => $path,
            'caller_cwd' => $this->hostCwd(),
        ], fn (ProgressEventType $type, array $payload): int => $this->renderProgressTerminalFrame($type, $payload));
    }
}
