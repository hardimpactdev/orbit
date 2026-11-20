<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Processes\Artifacts\ProcessArtifactPresets;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessUpgradeProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\ProcessArtifact;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

const UPGRADE_SHA = 'a3f1c2d4e5f60718293a4b5c6d7e8f90112233445566778899aabbccddeeff00';

beforeEach(function (): void {
    Http::preventStrayRequests();
    app()->instance(SwampDispatch::class, new class
    {
        public function start(Operation $operation): void {}
    });
});

function upgrade_router(string $desired = 'running', ?array $command = null, string $runtime = 'systemd'): Process
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'services', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.1', 'public_ssh_host' => '10.44.0.1', 'user' => 'orbit',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $node->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    test()->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);

    return Process::query()->create([
        'owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'ai-router', 'runtime' => $runtime,
        'runtime_config' => ['command' => $command ?? ['/usr/bin/env', 'MANAGEMENT_STATIC_PATH=/home/orbit/.config/cliproxyapi/static',
            '/home/orbit/.local/share/cliproxyapi/current/cli-proxy-api', '-config', '/home/orbit/.config/cliproxyapi/config.yaml']],
        'restart_policy' => 'always', 'desired_state' => $desired, 'status' => 'active',
    ]);
}

function upgrade_artifact(Process $process, ?string $version = '8.0.63'): ProcessArtifact
{
    return ProcessArtifact::query()->create(['process_id' => $process->id, 'source' => 'github_release', ...app(ProcessArtifactPresets::class)->get('cliproxyapi'), 'version' => $version]);
}

function upgrade_github(string $tag = 'v8.0.80', ?string $checksums = null): void
{
    $base = 'https://github.com/router-for-me/CLIProxyAPI/releases/download/'.$tag.'/';
    $asset = 'CLIProxyAPI_8.0.80_linux_amd64.tar.gz';
    Http::fake([
        'api.github.com/repos/router-for-me/CLIProxyAPI/releases/tags/'.$tag => Http::response(['tag_name' => $tag, 'draft' => false, 'prerelease' => false, 'assets' => [
            ['name' => $asset, 'browser_download_url' => $base.$asset, 'digest' => 'sha256:'.str_repeat('b', 64)],
            ['name' => 'CLIProxyAPI_8.0.80_darwin_arm64.tar.gz', 'browser_download_url' => $base.'CLIProxyAPI_8.0.80_darwin_arm64.tar.gz'],
            ['name' => 'checksums.txt', 'browser_download_url' => $base.'checksums.txt'],
        ]]),
        $base.'checksums.txt' => Http::response($checksums ?? str_repeat('c', 64).'  CLIProxyAPI_8.0.80_darwin_arm64.tar.gz'."\n".UPGRADE_SHA.'  '.$asset."\n"),
        'api.github.com/*' => Http::response(['message' => 'Not Found'], 404),
    ]);
}

/**
 * @param  array<string,mixed>  $reported  the result step's result.json
 * @param  array<string,string>  $steps  the native run's step statuses
 */
function upgrade_finish(Operation $operation, string $status, array $reported, ?string $code = null, array $steps = []): Operation
{
    $operation->forceFill(['status' => 'running', 'state' => [...$operation->state, 'swamp_run_id' => 'upgrade-run']])->save();

    return app(ProcessUpgradeProjection::class)->apply($operation, [
        'status' => $status, 'run_id' => 'upgrade-run', 'error_code' => $code,
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => $reported,
        'native_run' => ['status' => $status, 'jobs' => [['name' => 'upgrade', 'steps' => array_map(
            static fn (string $name, string $state): array => ['name' => $name, 'status' => $state], array_keys($steps), $steps)]]],
    ])->refresh();
}

describe('process:artifact:set and unset', function (): void {
    it('fills every field but the version from the cliproxyapi preset and records no Operation', function (): void {
        $process = upgrade_router();

        $this->putJson('/api/v1/processes/'.$process->id.'/artifact', ['preset' => 'cliproxyapi'])->assertOk()
            ->assertJsonPath('data.artifact', [
                'source' => 'github_release', 'repository' => 'router-for-me/CLIProxyAPI', 'asset' => 'CLIProxyAPI_{version}_linux_amd64.tar.gz',
                'binary' => 'cli-proxy-api', 'checksums' => 'checksums.txt', 'version_args' => ['--help'], 'version' => null,
                'previous_version' => null, 'sha256' => null, 'latest_version' => null, 'latest_checked_at' => null,
            ]);

        $this->putJson('/api/v1/processes/'.$process->id.'/artifact', ['preset' => 'cliproxyapi', 'version' => 'v8.0.63', 'checksums' => 'SHA256SUMS'])
            ->assertOk()->assertJsonPath('data.artifact.version', '8.0.63')->assertJsonPath('data.artifact.checksums', 'SHA256SUMS');
        expect(Operation::query()->count())->toBe(0)->and(ProcessArtifact::query()->count())->toBe(1);

        $this->deleteJson('/api/v1/processes/'.$process->id.'/artifact')->assertOk()->assertJsonPath('data.removed', true);
        expect(ProcessArtifact::query()->count())->toBe(0)->and($process->refresh()->status->value)->toBe('active');
    });

    it('refuses a command that does not run <folder>/current/<binary>, a Docker Process and an unknown preset', function (?array $command, string $runtime, array $body): void {
        $process = upgrade_router(command: $command, runtime: $runtime);

        $this->putJson('/api/v1/processes/'.$process->id.'/artifact', $body)->assertUnprocessable()
            ->assertJsonPath('error.code', 'process.artifact_invalid');
        expect(ProcessArtifact::query()->count())->toBe(0);
    })->with([
        'binary outside current' => [['/home/orbit/.local/share/cliproxyapi/cli-proxy-api'], 'systemd', ['preset' => 'cliproxyapi']],
        'folder in a later argument' => [['/usr/bin/stat', '/home/orbit/.local/share/cliproxyapi/current/cli-proxy-api'], 'systemd', ['preset' => 'cliproxyapi']],
        'folder after an env flag' => [['/usr/bin/env', '-i', '/home/orbit/.local/share/cliproxyapi/current/cli-proxy-api'], 'systemd', ['preset' => 'cliproxyapi']],
        'Docker Process' => [['cli-proxy-api'], 'docker', ['preset' => 'cliproxyapi']],
        'unknown preset' => [null, 'systemd', ['preset' => 'nope']],
        'asset without {version}' => [null, 'systemd', ['repository' => 'a/b', 'asset' => 'router.tar.gz', 'binary' => 'cli-proxy-api']],
    ]);
});

it('refuses a release folder that another Process on the Node names or works in', function (array $other): void {
    $process = upgrade_router();
    Process::query()->create(['owner_type' => Node::class, 'owner_id' => $process->owner_id, 'name' => 'router-copy', 'runtime' => 'systemd',
        'restart_policy' => 'always', 'desired_state' => 'running', 'status' => 'active', ...$other]);

    $this->putJson('/api/v1/processes/'.$process->id.'/artifact', ['preset' => 'cliproxyapi'])->assertUnprocessable()
        ->assertJsonPath('error.code', 'process.artifact_invalid');
})->with([
    'command' => [['runtime_config' => ['command' => ['/home/orbit/.local/share/cliproxyapi/current/cli-proxy-api', '-config', '/tmp/other.yaml']]]],
    'working directory' => [['runtime_config' => ['command' => ['/usr/bin/python3', 'worker.py']], 'working_directory' => '/home/orbit/.local/share/cliproxyapi/releases/8.0.17']],
]);

describe('process:upgrade admission', function (): void {
    it('holds the Process: no other mutation is admitted while the upgrade is open', function (string $verb): void {
        $process = upgrade_router();
        upgrade_artifact($process);
        upgrade_github();
        $this->postJson('/api/v1/processes/'.$process->id.'/upgrade', ['version' => '8.0.80'])->assertAccepted();

        expect(fn () => app(ProcessOperationAdmission::class)->execute($process->refresh(), $verb))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('operation.target_busy'));
        expect(Operation::query()->count())->toBe(1);
    })->with(['stop', 'start', 'restart', 'update', 'destroy']);

    it('holds a Process that the Node agent starts and restarts directly', function (string $verb): void {
        $process = upgrade_router();
        upgrade_artifact($process);
        upgrade_github();
        $this->postJson('/api/v1/processes/'.$process->id.'/upgrade', ['version' => '8.0.80'])->assertAccepted();
        config(['dependency_detection.services_node_id' => $process->owner_id]);
        $runtime = Mockery::mock(ProcessRuntimeManager::class);
        $runtime->shouldNotReceive('start', 'restart');
        $runtime->shouldReceive('assertCanStart');
        app()->instance(ProcessRuntimeManager::class, $runtime);

        $this->postJson('/api/v1/processes/'.$process->id.'/'.$verb)->assertConflict()->assertJsonPath('error.code', 'operation.target_busy');
    })->with(['start', 'restart']);

    it('refuses when the command or the release checks change while the release resolves', function (Closure $change): void {
        $process = upgrade_router();
        upgrade_artifact($process);
        upgrade_github();
        Http::globalRequestMiddleware(function ($request) use ($process, $change) {
            $change($process);

            return $request;
        });

        $this->postJson('/api/v1/processes/'.$process->id.'/upgrade', ['version' => '8.0.80'])->assertConflict()
            ->assertJsonPath('error.code', 'operation.target_busy');
        expect(Operation::query()->count())->toBe(0);
    })->with([
        'command moved to another folder' => [fn (Process $process) => Process::query()->whereKey($process->id)->update(['runtime_config' => json_encode(['command' => ['/srv/new/current/cli-proxy-api']])])],
        'checksums changed' => [fn (Process $process) => ProcessArtifact::query()->where('process_id', $process->id)->update(['checksums' => null])],
        'version arguments changed' => [fn (Process $process) => ProcessArtifact::query()->where('process_id', $process->id)->update(['version_args' => json_encode(['--version'])])],
    ]);

    it('refuses when the Process is stopped while the release resolves', function (): void {
        $process = upgrade_router();
        upgrade_artifact($process);
        upgrade_github();
        Http::globalRequestMiddleware(function ($request) use ($process) {
            Process::query()->whereKey($process->id)->update(['desired_state' => 'stopped']);

            return $request;
        });

        $this->postJson('/api/v1/processes/'.$process->id.'/upgrade', ['version' => '8.0.80'])->assertConflict()
            ->assertJsonPath('error.code', 'process.not_running');
        expect(Operation::query()->count())->toBe(0);
    });

    it('admits a process.upgrade with the resolved URL and the SHA-256 from the checksums asset', function (string $requested, string $tag): void {
        $process = upgrade_router();
        upgrade_artifact($process);
        upgrade_github($tag);

        $response = $this->postJson('/api/v1/processes/'.$process->id.'/upgrade', ['version' => $requested])
            ->assertAccepted()->assertJsonPath('data.kind', 'process.upgrade');

        $operation = Operation::query()->findOrFail($response->json('data.id'));
        expect($operation->input)->toMatchArray([
            'process_id' => $process->id, 'runtime' => 'systemd', 'folder' => '/home/orbit/.local/share/cliproxyapi', 'binary' => 'cli-proxy-api',
            'version' => '8.0.80', 'asset' => 'CLIProxyAPI_8.0.80_linux_amd64.tar.gz', 'sha256' => UPGRADE_SHA, 'version_args' => ['--help'],
            'url' => 'https://github.com/router-for-me/CLIProxyAPI/releases/download/'.$tag.'/CLIProxyAPI_8.0.80_linux_amd64.tar.gz',
        ])->and($operation->lock_key)->toBe('process:'.$process->id)
            ->and($process->refresh()->status->value)->toBe('active');
    })->with(['v tag' => ['8.0.80', 'v8.0.80'], 'leading v, bare tag' => ['v8.0.80', '8.0.80']]);

    it('takes the asset digest without a checksums asset', function (): void {
        $process = upgrade_router();
        upgrade_artifact($process)->update(['checksums' => null]);
        upgrade_github();

        $response = $this->postJson('/api/v1/processes/'.$process->id.'/upgrade', ['version' => '8.0.80'])->assertAccepted();

        expect(Operation::query()->findOrFail($response->json('data.id'))->input['sha256'])->toBe(str_repeat('b', 64));
    });

    it('succeeds at once with outcome unchanged for the recorded version and contacts nobody', function (): void {
        $process = upgrade_router();
        upgrade_artifact($process, '8.0.80');

        $response = $this->postJson('/api/v1/processes/'.$process->id.'/upgrade', ['version' => 'v8.0.80'])->assertAccepted()
            ->assertJsonPath('data.status', 'succeeded');

        expect(Operation::query()->findOrFail($response->json('data.id'))->result)
            ->toBe(['before' => '8.0.80', 'after' => '8.0.80', 'outcome' => 'unchanged', 'pruned' => []])
            ->and($process->refresh()->status->value)->toBe('active');
        Http::assertNothingSent();
    });

    it('refuses without changing anything', function (Closure $arrange, int $status, string $code): void {
        $process = upgrade_router();
        $arrange($process);

        $this->postJson('/api/v1/processes/'.$process->id.'/upgrade', ['version' => '8.0.80'])->assertStatus($status)
            ->assertJsonPath('error.code', $code);
        expect(Operation::query()->where('kind', 'process.upgrade')->count())->toBe(0)
            ->and($process->refresh()->status->value)->toBe('active');
    })->with([
        'no artifact' => [fn (Process $process) => null, 409, 'process.artifact_missing'],
        'stopped' => [function (Process $process): void {
            upgrade_artifact($process);
            $process->update(['desired_state' => 'stopped']);
        }, 409, 'process.not_running'],
        'no such release' => [function (Process $process): void {
            upgrade_artifact($process);
            Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);
        }, 422, 'process.version_unknown'],
        'no checksum line for the asset' => [function (Process $process): void {
            upgrade_artifact($process);
            upgrade_github(checksums: str_repeat('c', 64)."  other.tar.gz\n");
        }, 422, 'process.version_unknown'],
        'GitHub down' => [function (Process $process): void {
            upgrade_artifact($process);
            Http::fake(['*' => Http::response('', 502)]);
        }, 422, 'process.version_unknown'],
        'another Operation holds the Process' => [function (Process $process): void {
            upgrade_artifact($process);
            upgrade_github();
            Operation::query()->create(['kind' => 'process.restart', 'target_type' => 'process', 'target_id' => $process->id, 'node_id' => $process->owner_id,
                'input' => [], 'state' => [], 'status' => 'running', 'sequence' => 1, 'attempts' => 0]);
        }, 409, 'operation.target_busy'],
    ]);
});

describe('process.upgrade projection', function (): void {
    function upgrade_admitted(): array
    {
        $process = upgrade_router();
        upgrade_artifact($process);
        upgrade_github();
        $id = test()->postJson('/api/v1/processes/'.$process->id.'/upgrade', ['version' => '8.0.80'])->assertAccepted()->json('data.id');

        return [$process, Operation::query()->findOrFail($id)];
    }

    it('records the installed version after a pass and names before, after and the pruned versions', function (): void {
        [$process, $operation] = upgrade_admitted();

        $operation = upgrade_finish($operation, 'succeeded', ['before' => '8.0.63', 'after' => '8.0.80', 'outcome' => 'upgraded', 'pruned' => ['8.0.17']]);

        expect($operation->result)->toBe(['before' => '8.0.63', 'after' => '8.0.80', 'outcome' => 'upgraded', 'pruned' => ['8.0.17']])
            ->and($process->artifact()->firstOrFail()->only(['version', 'previous_version', 'sha256']))
            ->toBe(['version' => '8.0.80', 'previous_version' => '8.0.63', 'sha256' => UPGRADE_SHA])
            ->and($process->refresh()->status->value)->toBe('active');
    });

    it('keeps the Process active and the version unchanged after a rollback', function (): void {
        [$process, $operation] = upgrade_admitted();

        $operation = upgrade_finish($operation, 'failed', ['error_code' => 'process.upgrade_rolled_back', 'before' => '8.0.63', 'after' => '8.0.80', 'outcome' => 'rolled_back', 'pruned' => []], 'process.upgrade_rolled_back');

        expect($operation->error_code)->toBe('process.upgrade_rolled_back')
            ->and($operation->result)->toBe(['before' => '8.0.63', 'after' => '8.0.63', 'outcome' => 'rolled_back', 'pruned' => [], 'error_code' => 'process.upgrade_rolled_back'])
            ->and($process->artifact()->firstOrFail()->version)->toBe('8.0.63')
            ->and($process->refresh()->status->value)->toBe('active');
    });

    it('shows the Process as failed when the rollback fails too', function (): void {
        [$process, $operation] = upgrade_admitted();

        $operation = upgrade_finish($operation, 'failed', ['error_code' => 'process.rollback_failed', 'before' => '8.0.63', 'after' => '8.0.80', 'pruned' => []], 'process.rollback_failed');

        expect($operation->result)->toBe(['error_code' => 'process.rollback_failed'])
            ->and($process->artifact()->firstOrFail()->version)->toBe('8.0.63')
            ->and($process->refresh()->status->value)->toBe('failed')
            ->and($process->error_code)->toBe('process.rollback_failed');
    });

    it('leaves the Process lifecycle as it was when the run fails before it switches current', function (): void {
        [$process, $operation] = upgrade_admitted();
        $process->forceFill(['status' => 'failed', 'error_code' => 'process.runtime_failed', 'retired_runtime' => 'docker'])->save();

        upgrade_finish($operation, 'failed', ['error_code' => 'process.artifact_layout_invalid', 'before' => null, 'after' => '8.0.80', 'pruned' => []], 'process.artifact_layout_invalid');

        $process->refresh();
        expect($process->status->value)->toBe('failed')->and($process->error_code)->toBe('process.runtime_failed')
            ->and($process->retired_runtime?->value)->toBe('docker')
            ->and($process->artifact()->firstOrFail()->version)->toBe('8.0.63');
    });

    it('decides from the native run when the run ends without a result', function (string $status, array $steps, string $state, ?string $error): void {
        [$process, $operation] = upgrade_admitted();

        upgrade_finish($operation, $status, [], $status === 'failed' ? 'operation.native_failed' : null, $steps);

        expect($process->refresh()->status->value)->toBe($state)->and($process->error_code)->toBe($error);
    })->with([
        'SSH lost during the download' => ['failed', ['Read the installed release' => 'succeeded', 'Download the release' => 'failed', 'Switch current' => 'skipped'], 'active', null],
        'cancelled during the download' => ['cancelled', ['Read the installed release' => 'succeeded', 'Download the release' => 'cancelled'], 'active', null],
        'failed after the switch' => ['failed', ['Switch current' => 'succeeded', 'Restart the Process' => 'failed'], 'failed', 'process.rollback_failed'],
        'cancelled during the verify' => ['cancelled', ['Switch current' => 'succeeded', 'Restart the Process' => 'succeeded', 'Verify it runs' => 'cancelled'], 'failed', 'process.rollback_failed'],
    ]);

    it('ends the failure of an earlier upgrade after a verified pass and keeps a pending runtime cleanup', function (): void {
        [$process, $operation] = upgrade_admitted();
        $process->forceFill(['status' => 'failed', 'error_code' => 'process.rollback_failed', 'retired_runtime' => 'docker'])->save();

        upgrade_finish($operation, 'succeeded', ['before' => '8.0.63', 'after' => '8.0.80', 'outcome' => 'upgraded', 'pruned' => []]);

        $process->refresh();
        expect($process->status->value)->toBe('active')->and($process->error_code)->toBeNull()->and($process->retired_runtime?->value)->toBe('docker');
    });

    it('records nothing on an artifact that was replaced or removed while the upgrade ran', function (Closure $replace): void {
        [$process, $operation] = upgrade_admitted();
        $replace($process);

        upgrade_finish($operation, 'succeeded', ['before' => '8.0.63', 'after' => '8.0.80', 'outcome' => 'upgraded', 'pruned' => []]);

        $artifact = $process->artifact()->first();
        expect($artifact?->version)->not->toBe('8.0.80')->and($artifact?->sha256)->toBeNull();
    })->with([
        'another repository' => [fn (Process $process) => test()->putJson('/api/v1/processes/'.$process->id.'/artifact', ['repository' => 'other/router', 'asset' => 'router_{version}.tar.gz', 'binary' => 'cli-proxy-api'])->assertOk()],
        'unset, then set again' => [function (Process $process): void {
            test()->deleteJson('/api/v1/processes/'.$process->id.'/artifact')->assertOk();
            test()->putJson('/api/v1/processes/'.$process->id.'/artifact', ['preset' => 'cliproxyapi'])->assertOk();
        }],
    ]);
});
