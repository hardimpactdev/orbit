<?php

declare(strict_types=1);

use App\Domain\Operations\OperationRunner;
use App\Domain\Operations\OperationStatus;
use App\Models\InstanceDeployment;
use App\Models\Operation;
use App\Models\Route;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\Orb220DeploymentApiFixture;

function smoke_fixture(object $test, string $mode = 'passed'): array
{
    $test->freezeTime();
    $test->smokeSends = 0;
    $test->smokeReads = 0;
    enable_operation_queue();
    Http::preventStrayRequests();
    $fixture = Orb220DeploymentApiFixture::create();
    $fixture->instance->project->update(['repository_url' => 'https://github.com/acme/widgets.git']);
    $route = Route::query()->create(['project_id' => $fixture->instance->project_id, 'node_id' => $fixture->owner->id, 'domain' => 'smoke.example.test', 'status' => 'pending', 'publication' => 'private', 'provenance' => 'explicit']);
    $route->targets()->create(['instance_id' => $fixture->instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);
    $history = InstanceDeployment::query()->create(['instance_id' => $fixture->instance->id, 'release' => 'initial', 'commit' => str_repeat('a', 40), 'status' => 'succeeded', 'started_at' => now()]);
    $body = ['deployment_id' => $history->id, 'commit' => str_repeat('a', 40), 'release' => 'initial', 'base_url' => 'https://smoke.example.test', 'workflow' => '.github/workflows/production-smoke.yml', 'ref' => 'main'];
    GitHubTestSupport::storeApp();
    $run = ['id' => 91, 'run_attempt' => 2, 'workflow_id' => 42, 'event' => 'workflow_dispatch', 'head_sha' => str_repeat('b', 40), 'head_branch' => 'main', 'path' => $body['workflow'], 'display_title' => 'Smoke '.$history->id.' '.$body['commit'], 'created_at' => now()->toIso8601String(), 'status' => 'completed', 'conclusion' => in_array($mode, ['failed', 'failed-empty', 'failed-skipped'], true) ? 'failure' : 'success', 'repository' => ['full_name' => 'acme/widgets'], 'head_repository' => ['full_name' => 'acme/widgets']];
    $target = ['commit' => $body['commit'], 'deployment_id' => (string) $history->id, 'base_url' => $body['base_url'], 'run_id' => '91', 'run_attempt' => $mode === 'mismatch' ? '1' : '2'];
    $file = tmpfile();
    $name = stream_get_meta_data($file)['uri'];
    $zip = new ZipArchive;
    $zip->open($name, ZipArchive::OVERWRITE);
    $zip->addFromString('storage/framework/testing/smoke-target.json', json_encode($target, JSON_THROW_ON_ERROR));
    $case = $mode === 'failed' ? '<failure>assertion failed</failure>' : (str_contains($mode, 'skipped') ? '<skipped/>' : '');
    $xml = '<testsuites><testsuite>'.(str_contains($mode, 'empty') ? '' : '<testcase name="home">'.$case.'</testcase>').'<system-out>API_TOKEN=fixture-secret '.str_repeat('x', 70000).'</system-out></testsuite></testsuites>';
    $xml = match ($mode) {
        'malformed' => '<testsuites>',
        'doctype' => '<!DOCTYPE testsuites [<!ENTITY xxe SYSTEM "file:///not-a-real-secret">]><testsuites><testsuite><testcase>&xxe;</testcase></testsuite></testsuites>',
        'oversized' => str_repeat('x', 1024 * 1024 + 1),
        'aggregate' => str_replace('<testsuites>', '<testsuites failures="1">', $xml),
        default => $xml,
    };
    $zip->addFromString('storage/framework/testing/smoke.xml', $xml);
    $zip->close();
    $archive = file_get_contents($name);
    fclose($file);
    $path = 'https://api.github.com/repos/acme/widgets';
    Http::fake([
        $path.'/installation' => fn () => Http::response(['id' => 123, 'permissions' => ['actions' => $mode === 'permission' ? 'read' : 'write']]),
        'https://api.github.com/app/installations/123/access_tokens' => fn ($request) => fn () => Http::response(['token' => 'fixture-token', 'permissions' => $request['permissions']]),
        $path.'/commits/main' => fn () => Http::response(['sha' => str_repeat('b', 40)]),
        $path.'/actions/workflows/production-smoke.yml' => fn () => Http::response(['id' => 42, 'path' => $body['workflow'], 'state' => 'active']),
        $path.'/actions/workflows/42/dispatches' => function ($request) use ($mode, $body, $test) {
            $test->smokeSends++;
            expect(Operation::query()->sole()->state['intent'] ?? null)->toBeString();
            expect($request->data())->toBe(['ref' => 'main', 'inputs' => ['commit' => $body['commit'], 'deployment_id' => (string) $body['deployment_id'], 'base_url' => $body['base_url']]]);
            if ($mode === 'uncertain') {
                throw new ConnectionException;
            }

            return Http::response('', 204);
        },
        $path.'/actions/workflows/42/runs?*' => function () use ($test, $mode, $run) {
            $rows = $mode === 'uncertain' || ($mode === 'later' && $test->smokeReads++ === 0) ? [] : ($mode === 'ambiguous' ? [$run, [...$run, 'id' => 92]] : [$run]);

            return Http::response(['total_count' => count($rows), 'workflow_runs' => $rows]);
        },
        $path.'/actions/runs/91/attempts/2' => fn () => Http::response($mode === 'wrong-ref' ? [...$run, 'head_sha' => str_repeat('c', 40)] : $run),
        $path.'/actions/runs/91/artifacts?*' => fn () => Http::response(['total_count' => 1, 'artifacts' => [['id' => 72, 'name' => 'production-smoke-91-2', 'expired' => $mode === 'expired', 'size_in_bytes' => strlen($archive), 'digest' => 'sha256:'.hash('sha256', $archive)]]]),
        $path.'/actions/artifacts/72/zip' => fn () => Http::response('', 302, ['Location' => 'https://productionresultssa1.blob.core.windows.net/artifacts/smoke.zip']),
        'https://productionresultssa1.blob.core.windows.net/artifacts/smoke.zip' => function ($request) use ($archive) {
            expect($request->hasHeader('Authorization'))->toBeFalse();

            return Http::response($archive);
        },
    ]);
    $test->withServerVariables(['REMOTE_ADDR' => $fixture->caller->wireguard_ip]);

    return [$fixture, $body, '/api/v1/instances/'.$fixture->instance->id.'/smoke'];
}

it('dispatches once and retains verified attempt evidence with bounded redacted output', function (string $mode, string $expected): void {
    [$fixture, $body, $url] = smoke_fixture($this, $mode);
    $this->withHeader('Idempotency-Key', 'smoke.test')->postJson($url, $body)->assertAccepted()->assertJsonPath('data.kind', 'instance.smoke')->assertJsonPath('data.target_type', 'instance_smoke');
    $operation = Operation::query()->sole();
    expect($operation->lock_key)->toBe('instance_smoke:'.$fixture->instance->id);
    app(OperationRunner::class)->run($operation->id);
    $receipt = $this->getJson($url.'/receipt?request_key=smoke.test')->assertOk()->assertJsonPath('data.smoke.state', $expected)->assertJsonPath('data.smoke.workflow_sha', str_repeat('b', 40));
    if ($expected === 'passed') {
        $receipt->assertJsonPath('data.smoke.tests', 1)->assertJsonPath('data.smoke.run_attempt', 2)->assertJsonPath('data.smoke.artifacts.0.url', 'https://github.com/acme/widgets/actions/runs/91/artifacts/72');
        expect(strlen($receipt->json('data.smoke.output')))->toBeLessThanOrEqual(65536);
        expect($receipt->json('data.smoke.output'))->not->toContain('fixture-secret');
    }
    $this->postJson($url, $body)->assertAccepted()->assertJsonPath('data.id', $operation->id);
    expect($this->smokeSends)->toBe(1);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/dispatches'));
})->with([['passed', 'passed'], ['failed', 'failed'], ['skipped', 'unknown'], ['mismatch', 'unknown'], ['ambiguous', 'unknown'], ['wrong-ref', 'unknown'], ['expired', 'unknown'], ['empty', 'unknown'], ['malformed', 'unknown'], ['doctype', 'unknown'], ['oversized', 'unknown'], ['aggregate', 'unknown'], ['failed-empty', 'unknown'], ['failed-skipped', 'unknown']]);

it('keeps uncertain sends pending and recovers without a second POST', function (): void {
    [$fixture, $body, $url] = smoke_fixture($this, 'uncertain');
    $this->withHeader('Idempotency-Key', 'smoke.lost')->postJson($url, $body)->assertAccepted();
    $operation = Operation::query()->sole();
    app(OperationRunner::class)->run($operation->id);
    $this->getJson($url.'/receipt?request_key=smoke.lost')->assertOk()->assertJsonPath('data.smoke.state', 'pending');
    $operation->refresh()->update(['status' => OperationStatus::Queued, 'current_step' => 'dispatch', 'wait_delay_seconds' => null, 'next_read_at' => null]);
    $operation->steps()->where('name', 'dispatch')->update(['status' => 'running']);
    $operation->steps()->where('name', 'evidence')->update(['status' => 'pending']);
    app(OperationRunner::class)->run($operation->id);
    $this->travel(31)->minutes();
    app(OperationRunner::class)->read($operation->id);
    $this->getJson($url.'/receipt?request_key=smoke.lost')->assertOk()->assertJsonPath('data.smoke.state', 'unknown');
    expect($this->smokeSends)->toBe(1);
});

it('refuses missing permissions or invalid selection and body before dispatch', function (string $mode, array $change, int $status, string $code): void {
    [$fixture, $body, $url] = smoke_fixture($this, $mode);
    $this->withHeader('Idempotency-Key', 'smoke.invalid')->postJson($url, [...$body, ...$change])->assertStatus($status)->assertJsonPath('error.code', $code);
    expect(Operation::query()->count())->toBe(0);
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/dispatches'));
})->with([['permission', [], 422, 'instance.smoke_invalid'], ['passed', ['commit' => str_repeat('c', 40)], 409, 'instance.smoke_selection_changed'], ['passed', ['base_url' => 'https://foreign.test'], 422, 'instance.smoke_invalid'], ['passed', ['extra' => true], 422, 'instance.smoke_invalid']]);

it('binds replay to the full body caller and instance and keeps receipt reads inert', function (): void {
    [$fixture, $body, $url] = smoke_fixture($this);
    $this->getJson($url.'/receipt?request_key=missing')->assertOk()->assertJsonPath('data.found', false)->assertJsonPath('data.smoke', null);
    $this->postJson($url, $body)->assertStatus(422);
    $this->withHeader('Idempotency-Key', 'smoke.bound')->postJson($url, $body)->assertAccepted();
    $this->postJson($url, [...$body, 'ref' => 'other'])->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
    $fixture->owner->accessibleNodes()->attach($fixture->caller->id);
    $this->withServerVariables(['REMOTE_ADDR' => $fixture->owner->wireguard_ip])->getJson($url.'/receipt?request_key=smoke.bound')->assertConflict();
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/dispatches'));
});

it('rechecks selection after queueing and reports control plane failure as unknown smoke', function (): void {
    [$fixture, $body, $url] = smoke_fixture($this);
    $this->withHeader('Idempotency-Key', 'smoke.changed')->postJson($url, $body)->assertAccepted();
    $fixture->deployment->current = 'fresh';
    app(OperationRunner::class)->run(Operation::query()->sole()->id);
    $this->getJson($url.'/receipt?request_key=smoke.changed')->assertOk()->assertJsonPath('data.operation.error_code', 'instance.smoke_selection_changed')->assertJsonPath('data.smoke.state', 'unknown');
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
