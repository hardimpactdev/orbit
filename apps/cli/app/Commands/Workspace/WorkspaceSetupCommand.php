<?php

declare(strict_types=1);

namespace App\Commands\Workspace;

use App\Commands\Concerns\StreamsGatewayProgress;
use App\Services\Workspaces\CodexWorktreeNameResolver;
use Orbit\Core\Progress\ProgressEventType;

final class WorkspaceSetupCommand extends WorkspaceGatewayCommand
{
    use StreamsGatewayProgress;

    #[\Override]
    protected $signature = 'workspace:setup
        {name? : Workspace name}
        {--instance= : Instance selector (app.instance)}
        {--path= : Explicit workspace path to adopt}
        {--json : Output JSON}
        {--stream-json : Stream newline-delimited JSON progress frames}';

    #[\Override]
    protected $description = 'Converge a workspace to a ready-to-develop-in state.';

    public function handle(): int
    {
        $path = $this->stringOption('path');

        if ($path !== null && ! str_starts_with($path, '/')) {
            return $this->failValidation('path', 'Path must be absolute.');
        }

        $name = $this->stringArgument('name');

        if ($name === null && $path !== null) {
            $name = app(CodexWorktreeNameResolver::class)->resolve($path);
        }

        return $this->streamProgress(
            '/api/workspaces/setup',
            [
                'name' => $name,
                'instance' => $this->stringOption('instance') ?? $this->instanceFromOrbitMarker(),
                'path' => $path,
                'caller_cwd' => $this->hostCwd(),
            ],
            fn (ProgressEventType $type, array $payload): int => $this->renderCanonicalProgressTerminalFrame(
                $type,
                $payload,
            ),
        );
    }
}
