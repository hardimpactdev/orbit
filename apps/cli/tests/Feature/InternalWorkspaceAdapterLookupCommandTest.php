<?php

declare(strict_types=1);

use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Process\Process;

describe('internal workspace adapter lookup command', function (): void {
    beforeEach(function (): void {
        configureWorkspaceAdapterOperationTokenGuard();
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));

        $this->workspaceAdapterTemp = sys_get_temp_dir().'/orbit-cli-workspace-adapter-'.bin2hex(random_bytes(8));
        mkdir($this->workspaceAdapterTemp, recursive: true);

        putenv("ORBIT_POLYSCOPE_DB_PATH={$this->workspaceAdapterTemp}/polyscope.db");
        putenv("ORBIT_POLYSCOPE_SETTINGS_PATH={$this->workspaceAdapterTemp}/settings.json");
        putenv("ORBIT_OPENCODE_DB_PATH={$this->workspaceAdapterTemp}/opencode.db");
    });

    afterEach(function (): void {
        putenv('ORBIT_POLYSCOPE_DB_PATH');
        putenv('ORBIT_POLYSCOPE_SETTINGS_PATH');
        putenv('ORBIT_OPENCODE_DB_PATH');

        removeWorkspaceAdapterTempDirectory($this->workspaceAdapterTemp);
    });

    it('rejects a missing operation token before validating paths or opening adapter databases', function (): void {
        [$exitCode, $output] = runWorkspaceAdapterLookupCommand($this, [
            '--adapter' => 'polyscope',
            '--app-path' => '/srv/docs',
            '--workspace-path' => '/srv/docs/.worktrees/feature-docs',
            '--json' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('rejects an invalid operation token before opening adapter databases', function (): void {
        config()->set('orbit.gateway.url', null);
        app()->forgetInstance('App\Services\GatewayApiClient');
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');

        [$exitCode, $output] = runWorkspaceAdapterLookupCommand($this, [
            '--adapter' => 'polyscope',
            '--app-path' => '/srv/docs',
            '--workspace-path' => '/srv/docs/.worktrees/feature-docs',
            '--operation-token' => 'not-a-token',
            '--json' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe(JsonEnvelope::failure(
                'invalid_token',
                'Operation token is invalid.',
            ));
    });

    it('rejects an unsupported adapter before opening adapter databases', function (): void {
        [$exitCode, $output] = runWorkspaceAdapterLookupCommand($this, [
            '--adapter' => 'evil',
            '--app-path' => '/srv/docs',
            '--workspace-path' => '/srv/docs/.worktrees/feature-docs',
            '--operation-token' => workspaceAdapterSignedOperationToken(),
            '--json' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe(JsonEnvelope::failure(
                'validation_failed',
                'Workspace adapter must be one of: polyscope, opencode.',
                ['field' => 'adapter', 'adapter' => 'evil'],
            ));
    });

    it('returns the Polyscope workspace path lookup payload from a fixture database', function (): void {
        createPolyscopeWorkspaceDatabase("{$this->workspaceAdapterTemp}/polyscope.db");

        [$exitCode, $output] = runWorkspaceAdapterLookupCommand($this, [
            '--adapter' => 'polyscope',
            '--app-path' => '/srv/docs',
            '--workspace-path' => '/srv/docs/.worktrees/feature-docs',
            '--operation-token' => workspaceAdapterSignedOperationToken(),
            '--json' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toBe(json_encode(
                JsonEnvelope::success([
                    'match' => true,
                    'workspace_name' => 'feature-docs',
                    'path' => '/srv/docs/.worktrees/feature-docs',
                    'adapter_workspace_id' => 'poly-worktree-1',
                ]),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
    });

    it('returns the OpenCode workspace path lookup payload from a fixture database', function (): void {
        createOpenCodeWorkspaceDatabase("{$this->workspaceAdapterTemp}/opencode.db");

        [$exitCode, $output] = runWorkspaceAdapterLookupCommand($this, [
            '--adapter' => 'opencode',
            '--app-path' => '/srv/docs',
            '--workspace-path' => '/srv/docs/.worktrees/feature-open',
            '--operation-token' => workspaceAdapterSignedOperationToken(),
            '--json' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toBe(json_encode(
                JsonEnvelope::success([
                    'match' => true,
                    'workspace_name' => 'feature-open',
                    'path' => '/srv/docs/.worktrees/feature-open',
                    'adapter_workspace_id' => 'open-workspace-1',
                ]),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
    });

    it('returns only non-secret Polyscope config metadata (no raw auth token) for --lookup=config', function (): void {
        // TDD: demonstrates regression where Polyscope auth token was emitted as 'api_token'.
        // Required: only non-secret metadata (ids, urls) needed by callers; raw token never leaves result boundary.
        createPolyscopeConfigDatabase("{$this->workspaceAdapterTemp}/polyscope.db");
        file_put_contents("{$this->workspaceAdapterTemp}/settings.json", json_encode([
            'authToken' => 'poly-token',
            'serverId' => 'server-1',
            'backendUrl' => 'https://polyscope.test',
        ], JSON_THROW_ON_ERROR));

        [$exitCode, $output] = runWorkspaceAdapterLookupCommand($this, [
            '--adapter' => 'polyscope',
            '--lookup' => 'config',
            '--app-path' => '/srv/docs',
            '--operation-token' => workspaceAdapterSignedOperationToken(),
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($decoded)->toBe(JsonEnvelope::success([
                'server_id' => 'server-1',
                'repository_id' => 'repo-docs',
                'base_url' => 'https://polyscope.test',
            ]))
            ->and($output)->not->toContain('api_token')
            ->and($output)->not->toContain('poly-token')
            ->and($output)->not->toContain('authToken');
    });

    it('returns a failure envelope when the adapter database is missing', function (): void {
        [$exitCode, $output] = runWorkspaceAdapterLookupCommand($this, [
            '--adapter' => 'polyscope',
            '--app-path' => '/srv/docs',
            '--workspace-path' => '/srv/docs/.worktrees/feature-docs',
            '--operation-token' => workspaceAdapterSignedOperationToken(),
            '--json' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe(JsonEnvelope::failure(
                'adapter_database_missing',
                'Workspace adapter database does not exist.',
                ['adapter' => 'polyscope'],
            ));
    });

    it('returns a failure envelope when the adapter database schema cannot be queried', function (): void {
        createEmptySqliteDatabase("{$this->workspaceAdapterTemp}/opencode.db");

        [$exitCode, $output] = runWorkspaceAdapterLookupCommand($this, [
            '--adapter' => 'opencode',
            '--app-path' => '/srv/docs',
            '--workspace-path' => '/srv/docs/.worktrees/feature-open',
            '--operation-token' => workspaceAdapterSignedOperationToken(),
            '--json' => true,
        ]);

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toBe(JsonEnvelope::failure(
                'adapter_database_query_failed',
                'Workspace adapter database could not be queried.',
                ['adapter' => 'opencode'],
            ))
            ->and($output)->not->toContain('SQLSTATE')
            ->and($output)->not->toContain('PDOException');
    });

    it('hides the internal workspace adapter lookup command from php orbit list', function (): void {
        $process = new Process([PHP_BINARY, 'orbit', 'list'], base_path());
        $process->run();

        expect($process->getExitCode())->toBe(0)
            ->and($process->getOutput())->not->toContain('internal:workspace-adapter:lookup');
    });
});

function configureWorkspaceAdapterOperationTokenGuard(): void
{
    app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
}

function workspaceAdapterSignedOperationToken(
    string $id = 'workspace-adapter-lookup',
    string $node = 'app-dev',
    string $command = 'internal:workspace-adapter:lookup',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return (new OperationTokenSigner)->sign(
        secret: 'gateway-secret',
        id: $id,
        node: $node,
        command: $command,
        issuedAt: $issuedAt,
        expiresAt: $expiresAt,
    )->toString();
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function runWorkspaceAdapterLookupCommand(object $test, array $parameters = []): array
{
    $test->mockConsoleOutput = false;
    app()->offsetUnset(OutputStyle::class);

    $exitCode = $test->artisan('internal:workspace-adapter:lookup', $parameters);

    return [$exitCode, trim(app(Kernel::class)->output())];
}

function createPolyscopeWorkspaceDatabase(string $path): void
{
    $pdo = createWritableSqliteDatabase($path);
    $pdo->exec('create table repositories (id text primary key, path text not null)');
    $pdo->exec('create table worktrees (id text primary key, repo_id text not null, branch text not null, path text not null)');
    $pdo->exec("insert into repositories (id, path) values ('repo-docs', '/srv/docs/')");
    $pdo->exec("insert into worktrees (id, repo_id, branch, path) values ('poly-worktree-1', 'repo-docs', 'feature-docs', '/srv/docs/.worktrees/feature-docs/')");
}

function createOpenCodeWorkspaceDatabase(string $path): void
{
    $pdo = createWritableSqliteDatabase($path);
    $pdo->exec('create table project (id text primary key, worktree text not null)');
    $pdo->exec('create table workspace (id text primary key, project_id text not null, name text not null, branch text, directory text not null)');
    $pdo->exec("insert into project (id, worktree) values ('project-docs', '/srv/docs/')");
    $pdo->exec("insert into workspace (id, project_id, name, branch, directory) values ('open-workspace-1', 'project-docs', 'Docs fallback', 'feature-open', '/srv/docs/.worktrees/feature-open/')");
}

function createPolyscopeConfigDatabase(string $path): void
{
    $pdo = createWritableSqliteDatabase($path);
    $pdo->exec('create table repositories (id text primary key, path text not null)');
    $pdo->exec("insert into repositories (id, path) values ('repo-docs', '/srv/docs/')");
}

function createEmptySqliteDatabase(string $path): void
{
    createWritableSqliteDatabase($path);
}

function createWritableSqliteDatabase(string $path): PDO
{
    $pdo = new PDO("sqlite:{$path}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

function removeWorkspaceAdapterTempDirectory(?string $path): void
{
    if (! is_string($path) || ! is_dir($path)) {
        return;
    }

    $entries = scandir($path);

    if ($entries === false) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $entryPath = "{$path}/{$entry}";

        if (is_dir($entryPath)) {
            removeWorkspaceAdapterTempDirectory($entryPath);

            continue;
        }

        unlink($entryPath);
    }

    rmdir($path);
}
