<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

final readonly class WorkspaceSetupRetryCommandBuilder
{
    public function build(
        string $workspace,
        string $app,
        string $instance,
        ?string $path = null,
    ): string {
        $arguments = [
            'orbit workspace:setup',
            escapeshellarg($workspace),
            '--instance='.escapeshellarg("{$app}.{$instance}"),
        ];

        if ($path !== null) {
            $arguments[] = '--path='.escapeshellarg($path);
        }

        return implode(' ', $arguments);
    }
}
