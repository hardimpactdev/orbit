<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Domain\Instances\Deployment\ProductionDeployment;
use App\Domain\Operations\OperationRunner;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStepPlan;
use App\Domain\Operations\OperationStore;
use App\Domain\Operations\OperationSweeper;
use App\Domain\Shared\ResourceOperationException;
use App\Models\InstanceDeployment;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\Orb220DeploymentApiFixture;
use Tests\Support\ProductionSmokeRoute;

function smoke_fixture(object $test, string $mode = 'passed'): array
{
    $test->freezeTime();
    $test->smokeSends = 0;
    $test->smokeReads = 0;
    $test->smokeDownloads = 0;
    $test->smokePayloads = [];
    $test->intentSeen = [];
    enable_operation_queue();
    Http::preventStrayRequests();
    $fixture = Orb220DeploymentApiFixture::create();
    $fixture->instance->project->update(['repository_url' => 'https://github.com/acme/widgets.git']);
    ProductionSmokeRoute::create($fixture->instance);
    $history = InstanceDeployment::query()->create(['instance_id' => $fixture->instance->id, 'release' => 'initial',
        'commit' => str_repeat('a', 40), 'status' => 'succeeded', 'started_at' => now()]);
    $body = ['deployment_id' => $history->id, 'commit' => str_repeat('a', 40), 'release' => 'initial',
        'base_url' => 'https://smoke.example.test', 'workflow' => '.github/workflows/production-smoke.yml', 'ref' => 'main'];
    GitHubTestSupport::storeApp();
    $run = ['id' => 91, 'run_attempt' => 2, 'workflow_id' => 42, 'event' => 'workflow_dispatch',
        'head_sha' => str_repeat('b', 40), 'head_branch' => 'main', 'path' => $body['workflow'],
        'display_title' => 'Smoke '.$history->id.' '.$body['commit'], 'created_at' => now()->subSeconds($mode === 'skew' ? 55 : 0)
            ->toIso8601String(), 'status' => 'completed', 'conclusion' => match ($mode) {
                'clean-timeout' => 'timed_out', 'clean-cancelled' => 'cancelled',
                'failed', 'failed-empty', 'failed-skipped', 'clean-failure' => 'failure', default => 'success',
            }, 'repository' => ['full_name' => 'acme/widgets'], 'head_repository' => ['full_name' => 'acme/widgets']];
    $target = ['commit' => $body['commit'], 'deployment_id' => (string) $history->id, 'base_url' => $body['base_url'],
        'run_id' => '91', 'run_attempt' => $mode === 'mismatch' ? '1' : '2'];
    $file = tmpfile();
    $name = stream_get_meta_data($file)['uri'];
    $zip = new ZipArchive;
    $zip->open($name, ZipArchive::OVERWRITE);
    $zip->addFromString('storage/framework/testing/smoke-target.json', json_encode($target, JSON_THROW_ON_ERROR));
    $case = $mode === 'failed' ? '<failure>assertion failed</failure>' : (str_contains($mode, 'skipped') ? '<skipped/>' : '');
    $xml = '<testsuites><testsuite>'.(str_contains($mode,
        'empty') ? '' : '<testcase name="home">'.$case.'</testcase>').'<system-out>API_TOKEN=fixture-secret '.str_repeat('x',
            70000).'</system-out></testsuite></testsuites>';
    $xml = match ($mode) {
        'malformed' => '<testsuites>',
        'doctype' => '<!DOCTYPE testsuites [<!ENTITY xxe SYSTEM "file:///not-a-real-secret">]>'
            .'<testsuites><testsuite><testcase>&xxe;</testcase></testsuite></testsuites>',

        'oversized' => str_repeat('x', 1024 * 1024 + 1),
        'aggregate' => str_replace('<testsuites>', '<testsuites failures="1">', $xml),
        default => $xml,
    };
    if ($mode === 'utf16') {
        $xml = "\xff\xfe".mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8');
    }
    $zip->addFromString('storage/framework/testing/smoke.xml', $xml);
    if ($mode === 'entries') {
        for ($i = 0; $i < 128; $i++) {
            $zip->addFromString('extra-'.$i, 'x');
        }
    }
    if ($mode === 'bomb') {
        $zip->addFromString('bomb', str_repeat('0', 16 * 1024 * 1024 + 1));
    }
    $zip->close();
    $archive = file_get_contents($name);
    fclose($file);
    $path = 'https://api.github.com/repos/acme/widgets';
    Http::fake([
        $path.'/installation' => fn () => Http::response(['id' => 123,
            'permissions' => ['actions' => $mode === 'permission' ? 'read' : 'write']],
            $mode === 'missing' ? 404 : ($mode === 'outage' ? 503 : 200)),
        'https://api.github.com/app/installations/123/access_tokens' => fn ($request) => Http::response(['token' => 'fixture-token',
            'permissions' => $request['permissions']]),
        $path.'/commits/main' => function () use ($mode, $test) {
            $test->smokeResolves = ($test->smokeResolves ?? 0) + 1;

            return Http::response(['sha' => ($test->smokeConfig ?? null) === 'sha' ? 'main'
                : str_repeat($mode === 'prepare-retry' && $test->smokeResolves === 1 ? 'c' : 'b', 40)]);
        },
        $path.'/actions/workflows/production-smoke.yml' => fn () => Http::response(['id' => 42,
            'path' => ($test->smokeConfig ?? null) === 'path' ? '.github/workflows/other.yml' : $body['workflow'],
            'state' => ($test->smokeConfig ?? null) === 'inactive' ? 'disabled_manually' : 'active']),
        $path.'/actions/workflows/42/dispatches' => function ($request) use ($mode, $test) {
            $test->smokeSends++;
            $test->intentSeen[] = is_string(Operation::query()->latest('id')->first()->state['intent'] ?? null);
            $test->smokePayloads[] = $request->data();
            if ($mode === 'uncertain') {
                throw new ConnectionException;
            }

            return Http::response('', $mode === 'refused' ? 422 : 204);
        },
        $path.'/actions/workflows/42/runs?*' => function () use ($test, $mode, $run) {
            if (isset($test->duringRead)) {
                ($test->duringRead)();
            }
            if (in_array($mode, ['primary', 'secondary', 'unauthorized', 'forbidden'], true)
                && $test->smokeReads++ === 0) {
                return Http::response(['message' => $mode === 'secondary' ? 'You exceeded a secondary rate limit.' : 'Denied'],
                    $mode === 'unauthorized' ? 401 : 403, $mode === 'primary' ? ['x-ratelimit-remaining' => '0'] : []);
            }
            $rows = $mode === 'uncertain' || ($mode === 'later' && $test
                ->smokeReads++ === 0) ? [] : ($mode === 'ambiguous' ? [$run, [...$run, 'id' => 92]] : [$run]);

            return Http::response(['total_count' => count($rows), 'workflow_runs' => $rows]);
        },
        $path.'/actions/runs/91/attempts/2' => fn () => Http::response($mode === 'attempt' ? [...$run,
            'run_attempt' => 3] : ($mode === 'wrong-ref' ? [...$run, 'head_sha' => str_repeat('c', 40)] : $run)),
        $path.'/actions/runs/91/artifacts?*' => fn () => Http::response(['total_count' => 1, 'artifacts' => [['id' => 72,
            'name' => 'production-smoke-91-2', 'expired' => $mode === 'expired', 'size_in_bytes' => strlen($archive),
            'digest' => 'sha256:'.($mode === 'digest' ? str_repeat('0', 64) : hash('sha256', $archive))]]]),
        $path.'/actions/artifacts/72/zip' => fn () => Http::response('', 302,
            ['Location' => 'https://productionresultssa1.blob.core.windows.net/artifacts/smoke.zip']),
        'https://productionresultssa1.blob.core.windows.net/artifacts/smoke.zip' => function ($request) use ($archive, $mode, $test) {
            expect($request->hasHeader('Authorization'))->toBeFalse();

            return Http::response($archive, $mode === 'download' && $test->smokeDownloads++ === 0 ? 503 : 200);
        },
    ]);
    $test->withServerVariables(['REMOTE_ADDR' => $fixture->caller->wireguard_ip]);

    return [$fixture, $body, '/api/v1/instances/'.$fixture->instance->id.'/smoke'];
}

it('dispatches once and retains verified attempt evidence with bounded redacted output', function (string $mode,
    string $expected): void {
    [$fixture, $body, $url] = smoke_fixture($this, $mode);
    $this->withHeader('Idempotency-Key', 'smoke.test')->postJson($url, $body)->assertAccepted()->assertJsonPath('data.kind',
        'instance.smoke')->assertJsonPath('data.target_type', 'instance_smoke');
    $operation = Operation::query()->sole();
    expect($operation->lock_key)->toBe('instance_smoke:'.$fixture->instance->id);
    app(OperationRunner::class)->run($operation->id);
    $receipt = $this->getJson($url.'/receipt?request_key=smoke.test')->assertOk()->assertJsonPath('data.smoke.state',
        $expected)->assertJsonPath('data.smoke.workflow_sha', str_repeat('b', 40));
    if ($expected === 'passed') {
        $receipt->assertJsonPath('data.smoke.tests', 1)->assertJsonPath('data.smoke.run_attempt', 2)
            ->assertJsonPath('data.smoke.artifacts.0.url', 'https://github.com/acme/widgets/actions/runs/91/artifacts/72');
        expect(strlen($receipt->json('data.smoke.output')))->toBeLessThanOrEqual(65536);
        expect($receipt->json('data.smoke.output'))->not->toContain('fixture-secret');
    }
    $this->postJson($url, $body)->assertAccepted()->assertJsonPath('data.id', $operation->id);
    expect($this->smokeSends)->toBe(1);
    expect($this->intentSeen)->toBe([true]);
    expect($this->smokePayloads)->toBe([['ref' => 'main', 'inputs' => [
        'commit' => $body['commit'], 'deployment_id' => (string) $body['deployment_id'], 'base_url' => $body['base_url'],
    ]]]);
    if ($mode === 'aggregate') {
        foreach (['tests', 'failures', 'errors', 'skipped'] as $count) {
            $receipt->assertJsonPath('data.smoke.'.$count, 0);
        }
    }
    if (str_starts_with($mode, 'clean-')) {
        $receipt->assertJsonPath('data.operation.error_code', 'instance.smoke_run_unsuccessful');
    }
    expect($operation->refresh()->result)->not->toHaveKey('output');
    expect($operation->state['steps']['evidence'])->toBeNull();
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/dispatches'));
})->with([['passed', 'passed'], ['failed', 'failed'], ['skipped', 'unknown'], ['mismatch', 'unknown'], ['ambiguous',
    'unknown'], ['wrong-ref', 'unknown'], ['expired', 'unknown'], ['empty', 'unknown'], ['malformed', 'unknown'], ['doctype',
        'unknown'], ['oversized', 'unknown'], ['aggregate', 'unknown'], ['failed-empty', 'unknown'], ['failed-skipped', 'unknown'],
    ['clean-failure', 'unknown'], ['clean-timeout', 'unknown'], ['clean-cancelled', 'unknown'], ['digest', 'unknown'],
    ['entries', 'unknown'], ['bomb', 'unknown'], ['attempt', 'unknown'], ['utf16', 'unknown'], ['skew', 'passed']]);

it('keeps uncertain sends pending and recovers without a second POST', function (): void {
    [$fixture, $body, $url] = smoke_fixture($this, 'uncertain');
    $this->withHeader('Idempotency-Key', 'smoke.lost')->postJson($url, $body)->assertAccepted();
    $operation = Operation::query()->sole();
    app(OperationRunner::class)->run($operation->id);
    $this->getJson($url.'/receipt?request_key=smoke.lost')->assertOk()->assertJsonPath('data.smoke.state', 'pending');
    $operation->refresh()->update(['status' => OperationStatus::Queued, 'current_step' => 'dispatch',
        'wait_delay_seconds' => null, 'next_read_at' => null]);
    $operation->steps()->where('name', 'dispatch')->update(['status' => 'running']);
    $operation->steps()->where('name', 'evidence')->update(['status' => 'pending']);
    app(OperationRunner::class)->run($operation->id);
    $this->travel(13)->minutes();
    app(OperationRunner::class)->read($operation->id);
    $this->getJson($url.'/receipt?request_key=smoke.lost')->assertOk()->assertJsonPath('data.smoke.state', 'unknown');
    expect($this->smokeSends)->toBe(1);
});

it('refuses missing permissions or invalid selection and body before dispatch', function (string $mode, array $change,
    int $status, string $code): void {
    [$fixture, $body, $url] = smoke_fixture($this, $mode);
    $this->withHeader('Idempotency-Key', 'smoke.invalid')->postJson($url, [...$body, ...$change])->assertStatus($status)
        ->assertJsonPath('error.code', $code);
    expect(Operation::query()->count())->toBe(0);
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/dispatches'));
})->with([['permission', [], 422, 'instance.smoke_invalid'], ['passed', ['commit' => str_repeat('c', 40)], 409,
    'instance.smoke_selection_changed'], ['passed', ['base_url' => 'https://foreign.test'], 422, 'instance.smoke_invalid'],
    ['passed', ['extra' => true], 422, 'instance.smoke_invalid'], ['missing', [], 422, 'instance.smoke_invalid'], ['outage', [],
        503, 'instance.smoke_github_unavailable']]);

it('binds replay to the full body caller and instance and keeps receipt reads inert', function (): void {
    [$fixture, $body, $url] = smoke_fixture($this);
    $this->getJson($url.'/receipt?request_key=missing')->assertOk()->assertJsonPath('data.found', false)
        ->assertJsonPath('data.smoke', null);
    $this->postJson($url, $body)->assertStatus(422);
    $this->withHeader('Idempotency-Key', 'smoke.bound')->postJson($url, $body)->assertAccepted();
    $this->postJson($url, [...$body, 'ref' => 'other'])->assertConflict()->assertJsonPath('error.code',
        'operation.idempotency_conflict');
    $fixture->owner->accessibleNodes()->attach($fixture->caller->id);
    $this->withServerVariables(['REMOTE_ADDR' => $fixture->owner->wireguard_ip])
        ->getJson($url.'/receipt?request_key=smoke.bound')->assertConflict();
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/dispatches'));
});

it('rechecks selection after queueing and reports control plane failure as unknown smoke', function (): void {
    [$fixture, $body, $url] = smoke_fixture($this);
    $this->withHeader('Idempotency-Key', 'smoke.changed')->postJson($url, $body)->assertAccepted();
    $fixture->deployment->current = 'fresh';
    app(OperationRunner::class)->run(Operation::query()->sole()->id);
    $this->getJson($url.'/receipt?request_key=smoke.changed')->assertOk()->assertJsonPath('data.operation.error_code',
        'instance.smoke_selection_changed')->assertJsonPath('data.smoke.state', 'unknown');
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/dispatches'));
});

it('reads a later completed run without another dispatch', function (): void {
    [$fixture, $body, $url] = smoke_fixture($this, 'later');
    $this->withHeader('Idempotency-Key', 'smoke.later')->postJson($url, $body)->assertAccepted();
    $operation = Operation::query()->sole();
    app(OperationRunner::class)->run($operation->id);
    expect($operation->refresh()->status)->toBe(OperationStatus::Waiting);
    $this->travel(10)->seconds();
    app(OperationRunner::class)->read($operation->id);
    $this->getJson($url.'/receipt?request_key=smoke.later')->assertOk()->assertJsonPath('data.smoke.state', 'passed');
    expect($this->smokeSends)->toBe(1);
});

it('retries a transient artifact download and keeps the matched run pending', function (): void {
    [$fixture, $body, $url] = smoke_fixture($this, 'download');
    $this->withHeader('Idempotency-Key', 'smoke.download')->postJson($url, $body)->assertAccepted();
    $operation = Operation::query()->sole();
    app(OperationRunner::class)->run($operation->id);
    $this->getJson($url.'/receipt?request_key=smoke.download')->assertOk()
        ->assertJsonPath('data.smoke.state', 'pending')->assertJsonPath('data.smoke.run_id', 91);
    $this->travel(10)->seconds();
    app(OperationRunner::class)->read($operation->id);
    $this->getJson($url.'/receipt?request_key=smoke.download')->assertOk()->assertJsonPath('data.smoke.state', 'passed');
    expect($this->smokeSends)->toBe(1);
    expect(Http::recorded(fn ($request) => str_ends_with($request->url(), '/access_tokens')
        && $request['permissions'] === ['actions' => 'read']))->toHaveCount(1);
});

it('supersedes an older waiting smoke for a new selected deployment', function (): void {
    [$fixture, $body, $url] = smoke_fixture($this, 'uncertain');
    $this->withHeader('Idempotency-Key', 'smoke.old')->postJson($url, $body)->assertAccepted();
    $older = Operation::query()->sole();
    app(OperationRunner::class)->run($older->id);
    $this->withHeader('Idempotency-Key', 'smoke.same')->postJson($url, $body)
        ->assertConflict()->assertJsonPath('error.code', 'operation.target_busy');
    $fixture->deployment->current = 'fresh';
    $next = InstanceDeployment::query()->create([
        'instance_id' => $fixture->instance->id, 'release' => 'fresh', 'commit' => $body['commit'],
        'status' => 'succeeded', 'started_at' => now(),
    ]);
    $this->withHeader('Idempotency-Key', 'smoke.new')
        ->postJson($url, [...$body, 'deployment_id' => $next->id, 'release' => 'fresh'])->assertAccepted();
    expect($older->refresh()->status)->toBe(OperationStatus::Cancelled);
    expect($older->lock_key)->toBeNull();
    $this->getJson($url.'/receipt?request_key=smoke.old')->assertOk()->assertJsonPath('data.smoke.state', 'unknown');
    expect(Operation::query()->where('lock_key', 'instance_smoke:'.$fixture->instance->id)->count())->toBe(1);
    expect($this->smokeSends)->toBe(1);
});

it('retries preparation before intent and refreshes the workflow SHA', function (bool $exhausted): void {
    [$fixture, $body, $url] = smoke_fixture($this, 'prepare-retry');
    $this->withHeader('Idempotency-Key', 'smoke.prepare')->postJson($url, $body)->assertAccepted();
    $reads = 0;
    $reader = Mockery::mock(ProductionDeployment::class);
    $reader->shouldReceive('releases')->andReturnUsing(fn () => $fixture->deployment->releases($fixture->instance));
    $reader->shouldReceive('selected')->andReturnUsing(function () use ($fixture, &$reads, $exhausted) {
        if ($reads++ === 0 || $exhausted) {
            throw new ResourceOperationException('deployment.read_unavailable', 'Injected SSH failure.');
        }

        return $fixture->deployment->selected($fixture->instance);
    });
    app()->instance(ProductionDeployment::class, $reader);
    $operation = Operation::query()->sole();
    app(OperationRunner::class)->run($operation->id);
    expect($operation->refresh()->state)->not->toHaveKey('intent');
    expect($operation->state['workflow']['sha'])->toBe(str_repeat('c', 40));
    $this->travel(10)->seconds();
    app(OperationRunner::class)->read($operation->id);
    if ($exhausted) {
        $this->travel(10)->seconds();
        app(OperationRunner::class)->read($operation->id);
        $this->getJson($url.'/receipt?request_key=smoke.prepare')->assertOk()
            ->assertJsonPath('data.smoke.state', 'unknown')
            ->assertJsonPath('data.operation.error_code', 'instance.smoke_prepare_unavailable');
        expect($this->smokeSends)->toBe(0);
    } else {
        $this->getJson($url.'/receipt?request_key=smoke.prepare')->assertOk()
            ->assertJsonPath('data.smoke.state', 'passed')->assertJsonPath('data.smoke.workflow_sha', str_repeat('b', 40));
        expect($this->smokeSends)->toBe(1);
    }
})->with([false, true]);

it('finishes a definite dispatch refusal immediately as unknown', function (): void {
    [$fixture, $body, $url] = smoke_fixture($this, 'refused');
    $this->withHeader('Idempotency-Key', 'smoke.refused')->postJson($url, $body)->assertAccepted();
    $operation = Operation::query()->sole();
    app(OperationRunner::class)->run($operation->id);
    $this->getJson($url.'/receipt?request_key=smoke.refused')->assertOk()
        ->assertJsonPath('data.smoke.state', 'unknown')
        ->assertJsonPath('data.operation.error_code', 'instance.smoke_dispatch_refused');
    expect($operation->refresh()->status)->toBe(OperationStatus::Failed);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/runs?'));
});

it('retains unknown after cancellation without cancelling or dispatching GitHub work', function (): void {
    [$fixture, $body, $url] = smoke_fixture($this, 'uncertain');
    $this->withHeader('Idempotency-Key', 'smoke.cancel')->postJson($url, $body)->assertAccepted();
    $operation = Operation::query()->sole();
    app(OperationRunner::class)->run($operation->id);
    app(CancelOperationAction::class)->execute($operation->refresh());
    $this->getJson($url.'/receipt?request_key=smoke.cancel')->assertOk()
        ->assertJsonPath('data.smoke.state', 'unknown')->assertJsonPath('data.operation.status', 'cancelled');
    expect($this->smokeSends)->toBe(1);
});

it('recovers a worker lost before intent or during evidence without duplicate dispatch', function (string $step): void {
    [$fixture, $body, $url] = smoke_fixture($this, 'later');
    $this->withHeader('Idempotency-Key', 'smoke.crash')->postJson($url, $body)->assertAccepted();
    $operation = Operation::query()->sole();
    if ($step === 'evidence') {
        app(OperationRunner::class)->run($operation->id);
        $operation->refresh();
    } else {
        app(OperationStore::class)->plan($operation, [
            new OperationStepPlan('dispatch', true), new OperationStepPlan('evidence', true),
        ]);
        $operation->update(['state' => ['workflow' => ['id' => 42, 'sha' => str_repeat('c', 40)]]]);
    }
    $operation->update(['status' => OperationStatus::Running, 'worker' => 'lost-worker', 'current_step' => $step]);
    $operation->steps()->where('name', $step)->update(['status' => 'running']);
    app(OperationSweeper::class)->sweep();
    app(OperationRunner::class)->run($operation->id);
    if ($operation->refresh()->status === OperationStatus::Waiting) {
        $this->travel(10)->seconds();
        app(OperationRunner::class)->read($operation->id);
    }
    $this->getJson($url.'/receipt?request_key=smoke.crash')->assertOk()->assertJsonPath('data.smoke.state', 'passed');
    expect($this->smokeSends)->toBe(1);
})->with(['dispatch', 'evidence']);

it('refuses a receipt read against another Instance', function (): void {
    [$fixture, $body, $url] = smoke_fixture($this);
    $this->withHeader('Idempotency-Key', 'smoke.instance')->postJson($url, $body)->assertAccepted();
    $project = Project::query()->create([
        'name' => 'Other', 'slug' => 'other', 'repository_url' => 'https://github.com/acme/other.git',
    ]);
    $other = $fixture->instance->replicate();
    $other->project_id = $project->id;
    $other->checkout_path .= '-other';
    $other->save();
    $this->getJson('/api/v1/instances/'.$other->id.'/smoke/receipt?request_key=smoke.instance')->assertConflict();
    expect($this->smokeSends)->toBe(0);
});

it('cancels a running older smoke at its read boundary and accepts the same new key', function (): void {
    [$fixture, $body, $url] = smoke_fixture($this, 'uncertain');
    $this->withHeader('Idempotency-Key', 'smoke.running')->postJson($url, $body)->assertAccepted();
    $older = Operation::query()->sole();
    app(OperationRunner::class)->run($older->id);
    $fixture->deployment->current = 'fresh';
    $next = InstanceDeployment::query()->create([
        'instance_id' => $fixture->instance->id, 'release' => 'fresh', 'commit' => $body['commit'],
        'status' => 'succeeded', 'started_at' => now(),
    ]);
    $newBody = [...$body, 'deployment_id' => $next->id, 'release' => 'fresh'];
    $this->duringRead = function () use ($older, $url, $newBody): void {
        expect($older->refresh()->status)->toBe(OperationStatus::Running);
        $this->withHeader('Idempotency-Key', 'smoke.next')->postJson($url, $newBody)
            ->assertConflict()->assertJsonPath('error.code', 'operation.target_busy');
        expect($older->refresh()->cancel_requested_at)->not->toBeNull();
    };

    $this->travel(10)->seconds();
    app(OperationRunner::class)->read($older->id);

    expect($older->refresh()->status)->toBe(OperationStatus::Cancelled);
    expect($older->lock_key)->toBeNull();
    $this->getJson($url.'/receipt?request_key=smoke.running')->assertOk()
        ->assertJsonPath('data.smoke.state', 'unknown');
    $this->postJson($url, $newBody)->assertAccepted();
    expect($this->smokeSends)->toBe(1);
});

it('keeps primary and secondary rate limits pending and reuses the read token', function (string $mode): void {
    [$fixture, $body, $url] = smoke_fixture($this, $mode);
    $this->withHeader('Idempotency-Key', 'smoke.rate')->postJson($url, $body)->assertAccepted();
    $operation = Operation::query()->sole();

    app(OperationRunner::class)->run($operation->id);

    $this->getJson($url.'/receipt?request_key=smoke.rate')->assertOk()
        ->assertJsonPath('data.smoke.state', 'pending')
        ->assertJsonPath('data.operation.error_code', 'instance.smoke_github_unavailable');
    $this->travel(10)->seconds();
    app(OperationRunner::class)->read($operation->id);
    $this->getJson($url.'/receipt?request_key=smoke.rate')->assertOk()->assertJsonPath('data.smoke.state', 'passed');
    expect(Http::recorded(fn ($request) => str_ends_with($request->url(), '/access_tokens')
        && $request['permissions'] === ['actions' => 'read']))->toHaveCount(1);
    expect($this->smokeSends)->toBe(1);
})->with(['primary', 'secondary']);

it('reports an evidence auth refusal separately from invalid evidence', function (string $mode): void {
    [$fixture, $body, $url] = smoke_fixture($this, $mode);
    $this->withHeader('Idempotency-Key', 'smoke.auth')->postJson($url, $body)->assertAccepted();

    app(OperationRunner::class)->run(Operation::query()->sole()->id);

    $this->getJson($url.'/receipt?request_key=smoke.auth')->assertOk()
        ->assertJsonPath('data.smoke.state', 'unknown')
        ->assertJsonPath('data.operation.error_code', 'instance.smoke_github_auth');
    expect(app('cache')->get('orbit:github:token:actions:acme/widgets'))->toBeNull();
})->with(['unauthorized', 'forbidden']);

it('refuses definite workflow configuration errors without retrying preparation', function (string $config): void {
    [$fixture, $body, $url] = smoke_fixture($this);
    $this->withHeader('Idempotency-Key', 'smoke.config')->postJson($url, $body)->assertAccepted();
    $this->smokeConfig = $config;
    $operation = Operation::query()->sole();

    app(OperationRunner::class)->run($operation->id);

    $this->getJson($url.'/receipt?request_key=smoke.config')->assertOk()
        ->assertJsonPath('data.smoke.state', 'unknown')
        ->assertJsonPath('data.operation.error_code', 'instance.smoke_invalid')
        ->assertJsonPath('data.operation.status', 'failed');
    expect($operation->refresh()->state['prepare_attempts'])->toBe(1);
    expect($operation->state)->not->toHaveKey('intent');
    expect($this->smokeSends)->toBe(0);
})->with(['inactive', 'path', 'sha']);
