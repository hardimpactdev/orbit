<?php

declare(strict_types=1);

use App\Actions\GitHub\IssueDirectGitHubTokenAction;
use App\Domain\GitHub\GitHubTokenGrant;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Feature\GitHub\GitHubTestSupport;

beforeEach(function (): void {
    Exceptions::fake();
    Http::preventStrayRequests();
    $this->logs = [];
    Log::listen(function (MessageLogged $event): void {
        $this->logs[] = [$event->message, $event->context];
    });
    $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1', 'status' => LifecycleStatus::Active]));
    $this->worker = Node::query()->create(['name' => 'control', 'public_ssh_host' => '192.0.2.3', 'wireguard_ip' => '10.44.0.3', 'status' => LifecycleStatus::Active]);
    $this->worker->accessibleNodes()->attach($this->gateway);
    config(['dependency_detection.services_node_id' => $this->worker->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->worker->wireguard_ip]);
    $this->runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->operation = Operation::query()->create(['kind' => 'route.caddy_converge', 'target_type' => 'node_route_projection',
        'target_id' => $this->gateway->id, 'node_id' => $this->gateway->id, 'status' => OperationStatus::Queued,
        'state' => ['route_publication_writer' => 'direct', 'swamp_run_id' => $this->runId],
        'input' => ['repository' => 'foreign/repository', 'permissions' => ['administration' => 'write']]]);
    // Supply only a test declaration. No production family receives a credential grant in this PR.
    $definition = app(DirectOperation::class)->definition($this->operation->kind);
    $definition['github_token'] = ['repository' => 'hardimpactdev/orbit', 'permissions' => ['contents' => 'write', 'pull_requests' => 'write']];
    $reflection = new ReflectionClass(DirectOperation::class);
    $direct = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('definitions')->setValue($direct, [$this->operation->kind => $definition]);
    app()->instance(DirectOperation::class, $direct);
    $this->path = '/api/v1/direct-operations/'.$this->operation->id.'/github-token';
    $this->body = ['run_id' => $this->runId];
    GitHubTestSupport::storeApp();
    $this->freezeTime();
    $this->reply = ['token' => 'ephemeral-sentinel', 'expires_at' => now()->addHour()->format('Y-m-d\TH:i:s\Z'),
        'permissions' => ['contents' => 'write', 'pull_requests' => 'write', 'metadata' => 'read'],
        'repositories' => [['full_name' => 'hardimpactdev/orbit']]];
});

afterEach(function (): void {
    $stored = json_encode([Activity::query()->get()->toArray(), Operation::query()->get()->toArray(),
        $this->operation->steps()->get()->toArray(), $this->logs], JSON_THROW_ON_ERROR);
    expect($stored)->not->toContain('ephemeral-sentinel', 'private-upstream-sentinel');
    foreach (Exceptions::reported() as $exception) {
        expect((string) $exception)->not->toContain('ephemeral-sentinel', 'private-upstream-sentinel');
    }
});

it('returns fresh exact-scope credentials privately without changing public results or execution state', function (): void {
    Http::fake(['*/repos/hardimpactdev/orbit/installation' => Http::response(['id' => 7]), '*/access_tokens' => Http::response($this->reply, 201)]);
    $before = $this->operation->fresh()->toArray();
    foreach ([1, 2] as $_) {
        $this->postJson($this->path, $this->body)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.token', 'ephemeral-sentinel')->assertJsonPath('data.repository', 'hardimpactdev/orbit')
            ->assertJsonPath('data.permissions', ['contents' => 'write', 'pull_requests' => 'write'])
            ->assertJsonPath('data.expires_at', $this->reply['expires_at']);
    }
    $activities = Activity::query()->where('command', 'operation:github:mint')->get();
    expect($activities)->toHaveCount(2);
    foreach ($activities as $activity) {
        expect($activity->subject_type)->toBe(Operation::class)
            ->and($activity->subject_id)->toBe($this->operation->id)
            ->and($activity->status)->toBe('succeeded')
            ->and($activity->properties->toArray())->toBe([
                'operation_id' => $this->operation->id, 'run_id' => $this->runId,
                'caller_node_id' => $this->worker->id, 'repository' => 'hardimpactdev/orbit',
                'permissions' => ['contents' => 'write', 'pull_requests' => 'write'],
                'expires_at' => $this->reply['expires_at'],
            ]);
    }
    Http::assertSentCount(4);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.github.com/app/installations/7/access_tokens'
        && $request->data() === ['repositories' => ['orbit'], 'permissions' => ['contents' => 'write', 'pull_requests' => 'write']]);
    expect($this->operation->fresh()->toArray())->toBe($before);
    expect($this->operation->steps()->count())->toBe(0);
    $this->getJson('/api/v1/operations/'.$this->operation->id)->assertOk()->assertDontSee('ephemeral-sentinel');
});

it('refuses an unbound or foreign run before minting', function (?string $run): void {
    $state = ['route_publication_writer' => 'direct'];
    if ($run !== null) {
        $state['swamp_run_id'] = $run;
    }
    $this->operation->update(['state' => $state]);
    $this->postJson($this->path, $this->body)->assertConflict()
        ->assertJsonPath('error.code', $run === null ? 'operation.run_unbound' : 'operation.run_mismatch');
    Http::assertNothingSent();
})->with([null, 'fabd4059-fb6c-49d8-940e-01ae58aa3f38']);

it('binds a Node worker credential request to its own Operation Node', function (): void {
    config(['dependency_detection.services_node_id' => null, 'operation_executor.worker_node_ids' => [$this->worker->id]]);
    $this->postJson($this->path, $this->body)->assertForbidden()->assertJsonPath('error.code', 'operation.executor_forbidden');
    $this->operation->update(['node_id' => $this->worker->id]);
    Http::fake(['*/installation' => Http::response(['id' => 7]), '*/access_tokens' => Http::response($this->reply, 201)]);
    $this->postJson($this->path, $this->body)->assertOk();
    Http::assertSentCount(2);
});

it('refuses an unknown peer and missing Gateway access', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.50'])->postJson($this->path, $this->body)->assertForbidden();
    $this->worker->accessibleNodes()->detach();
    $this->withServerVariables(['REMOTE_ADDR' => $this->worker->wireguard_ip])->postJson($this->path, $this->body)->assertForbidden();
    Http::assertNothingSent();
});

it('refuses a cancelled terminal or non-direct intent before minting', function (string $condition): void {
    match ($condition) {
        'cancel' => $this->operation->update(['cancel_requested_at' => now()]),
        'terminal' => $this->operation->update(['status' => OperationStatus::Succeeded]),
        'polled' => $this->operation->update(['state' => ['route_publication_writer' => 'polled', 'swamp_run_id' => $this->runId]]),
        'undeclared' => app()->instance(DirectOperation::class, new DirectOperation),
    };
    $this->postJson($this->path, $this->body)->assertStatus(match ($condition) {
        'polled' => 422, 'undeclared' => 403, default => 409
    });
    Http::assertNothingSent();
})->with(['cancel', 'terminal', 'polled', 'undeclared']);

it('refuses caller-selected repository scope transport and duplicate fields', function (string $body): void {
    $this->call('POST', $this->path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], str_replace('RUN', $this->runId, $body))
        ->assertUnprocessable();
    Http::assertNothingSent();
})->with(['{"run_id":"RUN","repository":"other/repo"}', '{"run_id":"RUN","permissions":{"contents":"write"}}',
    '{"run_id":"RUN","url":"https://other.test"}', '{"run_id":"RUN","run_id":"RUN"}', '[]', 'not-json']);

it('refuses query scope overrides', function (): void {
    $this->postJson($this->path.'?repository=other/repo', $this->body)->assertUnprocessable();
    Http::assertNothingSent();
});

it('refuses an upstream scope repository or lifetime mismatch without leaking the credential', function (string $mutation): void {
    match ($mutation) {
        'scope' => $this->reply['permissions']['contents'] = 'read',
        'extra' => $this->reply['permissions']['administration'] = 'write',
        'repository' => $this->reply['repositories'][0]['full_name'] = 'other/repo',
        'multiple' => $this->reply['repositories'][] = ['full_name' => 'other/repo'],
        'expired' => $this->reply['expires_at'] = now()->subSecond()->format('Y-m-d\TH:i:s\Z'),
        'long' => $this->reply['expires_at'] = now()->addHours(2)->format('Y-m-d\TH:i:s\Z'),
        'empty' => $this->reply['token'] = '',
        'large' => $this->reply['token'] = str_repeat('x', 2049),
    };
    Http::fake(['*/installation' => Http::response(['id' => 7]), '*/access_tokens' => Http::response($this->reply, 201)]);
    $this->postJson($this->path, $this->body)->assertServiceUnavailable()->assertJsonPath('error.code', 'operation.github_token_unavailable')->assertDontSee('ephemeral-sentinel');
    Http::assertSentCount(2);
})->with(['scope', 'extra', 'repository', 'multiple', 'expired', 'long', 'empty', 'large']);

it('sanitizes a transport or upstream refusal without serializing private diagnostics', function (bool $connection): void {
    Http::fake($connection ? fn () => throw new ConnectionException('private-upstream-sentinel')
        : ['*/installation' => Http::response(['message' => 'private-upstream-sentinel'], 403)]);
    $this->postJson($this->path, $this->body)->assertStatus($connection ? 503 : 403)->assertDontSee('private-upstream-sentinel');
    if ($connection) {
        Http::assertNothingSent();
    } else {
        Http::assertSentCount(1);
    }
})->with([true, false]);

it('rejects unsafe checked-in grants before they can mint', function (array $definition): void {
    expect(fn () => GitHubTokenGrant::from($definition))->toThrow(LogicException::class);
    Http::assertNothingSent();
})->with([
    [['repository' => 'other/../repo', 'permissions' => ['contents' => 'read']]],
    [['repository' => 'hardimpactdev/orbit', 'permissions' => ['administration' => 'write']]],
    [['repository' => 'hardimpactdev/orbit', 'permissions' => ['checks' => 'write']]],
    [['repository' => 'hardimpactdev/orbit', 'permissions' => [], 'url' => 'https://other.test']],
]);

it('refreshes stale cancellation and terminal state before minting', function (bool $cancel): void {
    Http::fake(['*/installation' => Http::response(['id' => 7]), '*/access_tokens' => Http::response($this->reply, 201)]);
    Operation::query()->whereKey($this->operation->id)->update($cancel
        ? ['cancel_requested_at' => now()] : ['status' => OperationStatus::Succeeded]);
    expect(fn () => app(IssueDirectGitHubTokenAction::class)->handle($this->operation, $this->worker->id))
        ->toThrow(ResourceOperationException::class);
    Http::assertNothingSent();
    expect(Activity::query()->where('command', 'operation:github:mint')->count())->toBe(0);
})->with([true, false]);
