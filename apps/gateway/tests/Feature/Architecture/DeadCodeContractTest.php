<?php

declare(strict_types=1);

function deadCodeContractRepoRoot(): string
{
    $basePath = base_path();

    if (basename($basePath) === 'gateway' && basename(dirname($basePath)) === 'apps') {
        return dirname($basePath, 2);
    }

    return $basePath;
}

it('does not keep obsolete gateway-side stream client wrappers', function (): void {
    $repoRoot = deadCodeContractRepoRoot();

    expect("{$repoRoot}/apps/gateway/app/Http/Gateway/DeployRunGatewayStreamClient.php")->not->toBeFile()
        ->and("{$repoRoot}/apps/gateway/app/Http/Gateway/WorkspaceNewGatewayStreamClient.php")->not->toBeFile()
        ->and("{$repoRoot}/apps/gateway/app/Http/Gateway/WorkspaceSetupGatewayStreamClient.php")->not->toBeFile()
        ->and("{$repoRoot}/apps/gateway/app/Http/Gateway/ToolActionGatewayStreamClient.php")->not->toBeFile()
        ->and("{$repoRoot}/apps/gateway/app/Http/Gateway/Requests/Deploy/RunDeployStreamRequest.php")->not->toBeFile()
        ->and("{$repoRoot}/apps/gateway/app/Http/Gateway/Requests/Workspaces/CreateWorkspaceStreamRequest.php")->not->toBeFile()
        ->and("{$repoRoot}/apps/gateway/app/Http/Gateway/Requests/Workspaces/SetupWorkspaceStreamRequest.php")->not->toBeFile()
        ->and("{$repoRoot}/apps/gateway/app/Http/Gateway/Requests/Tools/ToolActionStreamRequest.php")->not->toBeFile();
});

it('does not keep the unused remote progress reporter wrapper', function (): void {
    $repoRoot = deadCodeContractRepoRoot();

    expect("{$repoRoot}/apps/gateway/app/Support/Cli/RemoteProgressReporter.php")->not->toBeFile();
});

it('keeps gateway-owned code behind the Orbit SDK boundary', function (): void {
    $repoRoot = deadCodeContractRepoRoot();
    $needle = 'Sa'.'loon';

    foreach (gatewaySdkBoundaryPhpFiles([
        "{$repoRoot}/apps/gateway/app",
        "{$repoRoot}/apps/gateway/tests",
    ]) as $path) {
        expect(file_get_contents($path) ?: '')
            ->not->toContain($needle, "{$path} imports SDK HTTP-client internals directly.");
    }
});

/**
 * @param  list<string>  $roots
 * @return list<string>
 */
function gatewaySdkBoundaryPhpFiles(array $roots): array
{
    $files = [];

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}
