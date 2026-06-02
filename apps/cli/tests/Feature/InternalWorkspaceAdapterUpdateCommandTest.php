<?php

declare(strict_types=1);

use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Process\Process;

describe('internal workspace adapter update command', function (): void {
    beforeEach(function (): void {
        configureWorkspaceAdapterUpdateOperationTokenGuard();
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));

        $this->workspaceAdapterUpdateTemp = sys_get_temp_dir().'/orbit-cli-workspace-adapter-update-'.bin2hex(random_bytes(8));
        mkdir($this->workspaceAdapterUpdateTemp, recursive: true);

        putenv("ORBIT_POLYSCOPE_DB_PATH={$this->workspaceAdapterUpdateTemp}/polyscope.db");
    });

    afterEach(function (): void {
        putenv('ORBIT_POLYSCOPE_DB_PATH');

        removeWorkspaceAdapterUpdateTempDirectory($this->workspaceAdapterUpdateTemp);
    });

    it('rejects a missing operation token before validating inputs or opening databases', function (): void {
        [$exitCode, $output] = runWorkspaceAdapterUpdateCommand($this, [
            '--adapter' => 'evil',
            '--update' => 'workspace-branch',
            '--workspace-id' => 'poly-worktree-1',
            '--branch' => 'feature-docs',
            '--json' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('rejects an invalid operation token before validating inputs or opening databases', function (): void {
        config()->set('orbit.gateway.url', null);
        app()->forgetInstance('App\Services\GatewayApiClient');
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');

        [$exitCode, $output] = runWorkspaceAdapterUpdateCommand($this, [
            '--adapter' => 'polyscope',
            '--update' => 'workspace-branch',
            '--workspace-id' => 'poly-worktree-1',
            '--branch' => 'feature-docs',
            '--operation-token' => 'not-a-token',
            '--json' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe(JsonEnvelope::failure(
                'invalid_token',
                'Operation token is invalid.',
            ));
    });

    it('rejects unsupported adapters and update actions', function (array $parameters, array $expected): void {
        [$exitCode, $output] = runWorkspaceAdapterUpdateCommand($this, [
            ...validWorkspaceAdapterUpdateOptions(),
            ...$parameters,
        ]);

        expect($exitCode)->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe($expected);
    })->with([
        'adapter' => [
            ['--adapter' => 'opencode'],
            JsonEnvelope::failure(
                'validation_failed',
                'Workspace adapter update supports only the polyscope adapter.',
                ['field' => 'adapter', 'adapter' => 'opencode'],
            ),
        ],
        'update' => [
            ['--update' => 'repository-path'],
            JsonEnvelope::failure(
                'validation_failed',
                'Workspace adapter update must be workspace-branch.',
                ['field' => 'update', 'update' => 'repository-path'],
            ),
        ],
    ]);

    it('rejects invalid workspace ids', function (array $parameters): void {
        [$exitCode, $output] = runWorkspaceAdapterUpdateCommand($this, [
            ...validWorkspaceAdapterUpdateOptions(),
            ...$parameters,
        ]);

        expect($exitCode)->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe(JsonEnvelope::failure(
                'validation_failed',
                'The --workspace-id option is invalid.',
                ['field' => 'workspace-id'],
            ));
    })->with([
        'missing' => [['--workspace-id' => null]],
        'empty' => [['--workspace-id' => '']],
        'blank' => [['--workspace-id' => '   ']],
        'null byte' => [['--workspace-id' => "poly\0worktree"]],
        'newline' => [['--workspace-id' => "poly\nworktree"]],
        'carriage return' => [['--workspace-id' => "poly\rworktree"]],
        'too long' => [['--workspace-id' => str_repeat('a', 256)]],
    ]);

    it('rejects invalid branch values', function (array $parameters): void {
        [$exitCode, $output] = runWorkspaceAdapterUpdateCommand($this, [
            ...validWorkspaceAdapterUpdateOptions(),
            ...$parameters,
        ]);

        expect($exitCode)->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe(JsonEnvelope::failure(
                'validation_failed',
                'The --branch option is invalid.',
                ['field' => 'branch'],
            ));
    })->with([
        'missing' => [['--branch' => null]],
        'empty' => [['--branch' => '']],
        'blank' => [['--branch' => '   ']],
        'null byte' => [['--branch' => "feature\0docs"]],
        'newline' => [['--branch' => "feature\ndocs"]],
        'carriage return' => [['--branch' => "feature\rdocs"]],
        'too long' => [['--branch' => str_repeat('a', 256)]],
    ]);

    it('updates the Polyscope workspace branch for text workspace ids in the fixture database', function (string $workspaceId): void {
        createPolyscopeWorkspaceUpdateDatabase("{$this->workspaceAdapterUpdateTemp}/polyscope.db", $workspaceId);

        [$exitCode, $output] = runWorkspaceAdapterUpdateCommand($this, validWorkspaceAdapterUpdateOptions([
            '--workspace-id' => $workspaceId,
            '--branch' => 'feature-docs',
        ]));

        $row = workspaceAdapterUpdateDatabaseRow("{$this->workspaceAdapterUpdateTemp}/polyscope.db", $workspaceId);

        expect($exitCode)->toBe(0)
            ->and($output)->toBe(json_encode(
                JsonEnvelope::success([
                    'adapter' => 'polyscope',
                    'update' => 'workspace-branch',
                    'workspace_id' => $workspaceId,
                    'branch' => 'feature-docs',
                    'updated' => true,
                ]),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ))
            ->and($row)->toMatchArray([
                'id' => $workspaceId,
                'branch' => 'feature-docs',
                'branch_renamed' => 1,
            ]);
    })->with([
        'lookup fixture id' => ['poly-worktree-1'],
        'sdk fixture id' => ['wt-1'],
    ]);

    it('returns a failure envelope when the Polyscope database is missing', function (): void {
        [$exitCode, $output] = runWorkspaceAdapterUpdateCommand($this, validWorkspaceAdapterUpdateOptions());

        expect($exitCode)->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe(JsonEnvelope::failure(
                'database_missing',
                'Polyscope database does not exist.',
                ['adapter' => 'polyscope'],
            ));
    });

    it('returns a failure envelope when the Polyscope database is not writable', function (): void {
        createPolyscopeWorkspaceUpdateDatabase("{$this->workspaceAdapterUpdateTemp}/polyscope.db");
        chmod("{$this->workspaceAdapterUpdateTemp}/polyscope.db", 0444);

        [$exitCode, $output] = runWorkspaceAdapterUpdateCommand($this, validWorkspaceAdapterUpdateOptions());

        chmod("{$this->workspaceAdapterUpdateTemp}/polyscope.db", 0644);

        expect($exitCode)->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe(JsonEnvelope::failure(
                'database_unwritable',
                'Polyscope database is not writable.',
                ['adapter' => 'polyscope'],
            ));
    });

    it('returns a failure envelope when the workspace row is not found', function (): void {
        createPolyscopeWorkspaceUpdateDatabase("{$this->workspaceAdapterUpdateTemp}/polyscope.db");

        [$exitCode, $output] = runWorkspaceAdapterUpdateCommand($this, validWorkspaceAdapterUpdateOptions([
            '--workspace-id' => 'missing-worktree-404',
        ]));

        expect($exitCode)->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe(JsonEnvelope::failure(
                'workspace_not_found',
                'Polyscope workspace was not found.',
                ['adapter' => 'polyscope', 'workspace_id' => 'missing-worktree-404'],
            ));
    });

    it('returns a sanitized failure envelope when the update statement fails', function (): void {
        createEmptyWorkspaceAdapterUpdateDatabase("{$this->workspaceAdapterUpdateTemp}/polyscope.db");

        [$exitCode, $output] = runWorkspaceAdapterUpdateCommand($this, validWorkspaceAdapterUpdateOptions());

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toBe(JsonEnvelope::failure(
                'update_failed',
                'Polyscope database update failed.',
                ['adapter' => 'polyscope', 'update' => 'workspace-branch'],
            ))
            ->and($output)->not->toContain('SQLSTATE')
            ->and($output)->not->toContain('PDOException')
            ->and($output)->not->toContain('worktrees');
    });

    it('hides the internal workspace adapter update command from php orbit list', function (): void {
        $process = new Process([PHP_BINARY, 'orbit', 'list'], base_path());
        $process->run();

        expect($process->getExitCode())->toBe(0)
            ->and($process->getOutput())->not->toContain('internal:workspace-adapter:update');
    });
});

function configureWorkspaceAdapterUpdateOperationTokenGuard(): void
{
    app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
}

function workspaceAdapterUpdateSignedOperationToken(
    string $id = 'workspace-adapter-update',
    string $node = 'app-dev',
    string $command = 'internal:workspace-adapter:update',
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
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validWorkspaceAdapterUpdateOptions(array $overrides = []): array
{
    return [
        '--adapter' => 'polyscope',
        '--update' => 'workspace-branch',
        '--workspace-id' => 'poly-worktree-1',
        '--branch' => 'feature-docs',
        '--operation-token' => workspaceAdapterUpdateSignedOperationToken(),
        '--json' => true,
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function runWorkspaceAdapterUpdateCommand(object $test, array $parameters = []): array
{
    $test->mockConsoleOutput = false;
    app()->offsetUnset(OutputStyle::class);

    $exitCode = $test->artisan('internal:workspace-adapter:update', array_filter(
        $parameters,
        static fn (mixed $value): bool => $value !== null,
    ));

    return [$exitCode, trim(app(Kernel::class)->output())];
}

function createPolyscopeWorkspaceUpdateDatabase(string $path, string $workspaceId = 'poly-worktree-1'): void
{
    $pdo = createWritableWorkspaceAdapterUpdateDatabase($path);
    $pdo->exec('create table worktrees (id text primary key, branch text not null, branch_renamed integer not null default 0)');

    $statement = $pdo->prepare('insert into worktrees (id, branch, branch_renamed) values (:id, :branch, 0)');
    $statement->bindValue(':id', $workspaceId, PDO::PARAM_STR);
    $statement->bindValue(':branch', 'main', PDO::PARAM_STR);
    $statement->execute();
}

function createEmptyWorkspaceAdapterUpdateDatabase(string $path): void
{
    createWritableWorkspaceAdapterUpdateDatabase($path);
}

function createWritableWorkspaceAdapterUpdateDatabase(string $path): PDO
{
    $pdo = new PDO("sqlite:{$path}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    return $pdo;
}

/**
 * @return array{id: string, branch: string, branch_renamed: int}
 */
function workspaceAdapterUpdateDatabaseRow(string $path, string $id): array
{
    $pdo = createWritableWorkspaceAdapterUpdateDatabase($path);
    $statement = $pdo->prepare('select id, branch, branch_renamed from worktrees where id = :id');
    $statement->bindValue(':id', $id, PDO::PARAM_STR);
    $statement->execute();

    $row = $statement->fetch();

    expect($row)->toBeArray();

    return $row;
}

function removeWorkspaceAdapterUpdateTempDirectory(?string $path): void
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
            removeWorkspaceAdapterUpdateTempDirectory($entryPath);

            continue;
        }

        chmod($entryPath, 0644);
        unlink($entryPath);
    }

    rmdir($path);
}
