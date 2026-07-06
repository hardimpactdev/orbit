<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Process\Process;

describe('internal workspace source create command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));

        $this->workspaceSourceCreateTemp =
            sys_get_temp_dir().'/orbit-cli-workspace-source-create-'.bin2hex(random_bytes(8));
        mkdir($this->workspaceSourceCreateTemp, recursive: true);
    });

    afterEach(function (): void {
        removeWorkspaceSourceCreateTempDirectory($this->workspaceSourceCreateTemp);
    });

    it('rejects a missing operation token before creating a worktree', function (): void {
        Artisan::call('internal:workspace-source:create', [
            'app-path' => "{$this->workspaceSourceCreateTemp}/app",
            'workspace' => 'feature-docs',
            'base' => 'main',
            '--json' => true,
        ]);

        expect(Artisan::output())
            ->json()
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('creates a git worktree through fixed argv operations', function (): void {
        $appPath = "{$this->workspaceSourceCreateTemp}/app";
        mkdir($appPath);
        workspaceSourceCreateRunProcess(['git', 'init', '-q', '--initial-branch=main'], $appPath);
        workspaceSourceCreateRunProcess(['git', 'config', 'user.email', 'orbit@example.test'], $appPath);
        workspaceSourceCreateRunProcess(['git', 'config', 'user.name', 'Orbit Test'], $appPath);
        file_put_contents("{$appPath}/README.md", "test\n");
        workspaceSourceCreateRunProcess(['git', 'add', 'README.md'], $appPath);
        workspaceSourceCreateRunProcess(['git', 'commit', '-q', '-m', 'Initial'], $appPath);

        $exitCode = Artisan::call('internal:workspace-source:create', [
            'app-path' => $appPath,
            'workspace' => 'feature-docs',
            'base' => 'main',
            '--operation-token' => workspace_source_create_signed_operation_token(),
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $workspacePath = "{$appPath}/.worktrees/feature-docs";

        expect($exitCode)
            ->toBe(0)
            ->and($payload['success']['data'] ?? null)
            ->toMatchArray([
                'app_path' => $appPath,
                'workspace' => 'feature-docs',
                'base' => 'main',
                'path' => $workspacePath,
            ])
            ->and(is_dir($workspacePath))
            ->toBeTrue()
            ->and(
                trim(workspaceSourceCreateRunProcess(['git', 'branch', '--show-current'], $workspacePath)->getOutput()),
            )
            ->toBe('feature-docs');
    });

    it('rejects invalid workspace names', function (): void {
        Artisan::call('internal:workspace-source:create', [
            'app-path' => "{$this->workspaceSourceCreateTemp}/app",
            'workspace' => 'feature/docs',
            'base' => 'main',
            '--operation-token' => workspace_source_create_signed_operation_token(),
            '--json' => true,
        ]);

        expect(Artisan::output())
            ->json()
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'The workspace value is invalid.',
                ['field' => 'workspace'],
            ));
    });
});

function workspace_source_create_signed_operation_token(
    string $id = 'workspace-source-create',
    string $node = 'app-dev',
    string $command = 'internal:workspace-source:create',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: workspace_source_create_operation_secret(),
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

function workspace_source_create_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

/**
 * @param  list<string>  $command
 */
function workspaceSourceCreateRunProcess(array $command, string $cwd): Process
{
    $process = new Process($command, $cwd);
    $process->setTimeout(30);
    $process->mustRun();

    return $process;
}

function removeWorkspaceSourceCreateTempDirectory(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $file) {
        $file->isDir() && ! $file->isLink()
            ? rmdir($file->getPathname())
            : unlink($file->getPathname());
    }

    rmdir($path);
}
