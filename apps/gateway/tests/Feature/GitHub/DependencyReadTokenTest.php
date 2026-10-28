<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Feature\GitHub\GitHubTestSupport;

beforeEach(function (): void {
    Exceptions::fake();
    $this->capturedLogs = [];
    Log::listen(function (MessageLogged $event): void {
        $this->capturedLogs[] = [$event->message, $event->context];
    });
    $gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1', 'status' => LifecycleStatus::Active]));
    $this->services = Node::query()->create(['name' => 'services', 'public_ssh_host' => '192.0.2.3', 'wireguard_ip' => '10.44.0.3', 'status' => LifecycleStatus::Active]);
    $this->services->accessibleNodes()->attach($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.3']);
    config(['dependency_detection.services_node_id' => $this->services->id]);
    $this->project = Project::query()->create(['name' => 'Website', 'slug' => 'website', 'repository_url' => 'git@github.com:nckrtl/website.git', 'source_access' => 'github_app']);
    $this->tokenUrl = '/api/v1/projects/'.$this->project->id.'/dependency-read-token';
    GitHubTestSupport::storeApp();
    $this->freezeTime();
    $this->tokenReply = ['token' => 'ephemeral-sentinel', 'expires_at' => now()->addHour()->format('Y-m-d\TH:i:s\Z'),
        'permissions' => ['contents' => 'read', 'pull_requests' => 'read', 'metadata' => 'read'],
        'repositories' => [['full_name' => 'nckrtl/website']]];
});

afterEach(function (): void {
    $captured = json_encode([Activity::query()->get()->toArray(), Operation::query()->get()->toArray(), $this->capturedLogs], flags: JSON_THROW_ON_ERROR);
    foreach (['ephemeral-sentinel', 'ephemeral_sentinel', 'secret upstream body'] as $sentinel) {
        expect($captured)->not->toContain($sentinel);
        foreach (Exceptions::reported() as $exception) {
            expect((string) $exception)->not->toContain($sentinel);
        }
    }
});

it('mints a fresh one-repository read token without activity or operations', function (): void {
    $activities = Activity::query()->count();
    $operations = Operation::query()->count();
    $wire = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/GitHub/dependency-read-token-v1.json'), true, flags: JSON_THROW_ON_ERROR);
    $wire['data']['project_id'] = $this->project->id;
    $wire['data']['expires_at'] = $this->tokenReply['expires_at'];
    $this->tokenReply['token'] = $wire['data']['token'];
    Http::fake(['*/repos/nckrtl/website/installation' => Http::response(['id' => 7]), '*/access_tokens' => Http::response($this->tokenReply, 201)]);
    foreach ([1, 2] as $_) {
        $this->call('POST', $this->tokenUrl, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson($wire);
    }
    Http::assertSentCount(4);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.github.com/app/installations/7/access_tokens'
        && $request->data() === ['repositories' => ['website'], 'permissions' => ['contents' => 'read', 'pull_requests' => 'read']]);
    expect(Activity::query()->count())->toBe($activities)->and(Operation::query()->count())->toBe($operations);
});

it('refuses an unconfigured or different Services identity even with Gateway access', function (mixed $setting): void {
    Http::fake();
    config(['dependency_detection.services_node_id' => $setting]);
    $this->withHeaders(['X-Forwarded-For' => '10.44.0.3'])->call('POST', $this->tokenUrl, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}')
        ->assertForbidden()->assertJsonPath('error.code', 'dependency_token.forbidden');
    Http::assertNothingSent();
})->with([null, '', 0, '17garbage', 99999]);

it('rejects unknown peers and Services without Gateway access', function (): void {
    Http::fake();
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.5'])->withHeader('X-Forwarded-For', '10.44.0.3')
        ->call('POST', $this->tokenUrl, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}')->assertForbidden()->assertJsonPath('error.code', 'peer.identity_unknown');
    $this->services->accessibleNodes()->detach();
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.3'])->call('POST', $this->tokenUrl, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}')
        ->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
    Http::assertNothingSent();
});

it('refuses caller-selected token scope', function (): void {
    Http::fake();
    $this->postJson($this->tokenUrl, ['repository' => 'other/repo'])->assertUnprocessable();
    $this->postJson($this->tokenUrl.'?installation=7')->assertUnprocessable();
    Http::assertNothingSent();
});

it('refuses missing Projects and unsupported repository access', function (): void {
    Http::fake();
    $this->call('POST', '/api/v1/projects/99999/dependency-read-token', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}')->assertNotFound()->assertJsonPath('error.code', 'project.not_found');
    foreach ([['repository_url' => 'https://example.com/nckrtl/website'], ['repository_url' => 'https://github.com/nckrtl/website', 'source_access' => 'gh_cli']] as $values) {
        $this->project->update($values);
        $this->call('POST', $this->tokenUrl, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}')->assertUnprocessable()->assertJsonPath('error.code', 'dependency_token.repository_unavailable');
    }
    Http::assertNothingSent();
});

it('returns safe codes for upstream refusal and timeout', function (int $status, string $code): void {
    Http::fake(['*/installation' => Http::response(['id' => 7]), '*/access_tokens' => Http::response(['message' => 'secret upstream body'], $status)]);
    $this->call('POST', $this->tokenUrl, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}')->assertStatus($status === 403 ? 403 : 503)->assertJsonPath('error.code', $code)
        ->assertDontSee('secret upstream body');
})->with([[403, 'dependency_token.refused'], [500, 'dependency_token.unavailable'], [401, 'dependency_token.unavailable'], [429, 'dependency_token.unavailable']]);

it('returns unavailable on connection failure without the original exception', function (): void {
    Http::fake(fn () => throw new ConnectionException('ephemeral-sentinel'));
    $this->call('POST', $this->tokenUrl, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}')->assertServiceUnavailable()->assertJsonPath('error.code', 'dependency_token.unavailable')->assertDontSee('ephemeral-sentinel');
});

it('refuses absent installations', function (): void {
    Http::fake(['*/installation' => Http::response(['message' => 'private installation'], 404)]);
    $this->call('POST', $this->tokenUrl, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}')->assertForbidden()->assertJsonPath('error.code', 'dependency_token.refused')->assertDontSee('private installation');
    Http::assertSentCount(1);
});

it('refuses unsafe returned scope or lifetime', function (string $mutation): void {
    $reply = $this->tokenReply;
    match ($mutation) {
        'write' => $reply['permissions']['contents'] = 'write',
        'extra' => $reply['permissions']['actions'] = 'read',
        'repository' => $reply['repositories'] = [['full_name' => 'other/repo']],
        'multiple' => $reply['repositories'][] = ['full_name' => 'acme/second'],
        'expired' => $reply['expires_at'] = now()->subSecond()->format('Y-m-d\TH:i:s\Z'),
        'long' => $reply['expires_at'] = now()->addHours(2)->format('Y-m-d\TH:i:s\Z'),
        'missing' => $reply['token'] = '',
    };
    Http::fake(['*/installation' => Http::response(['id' => 7]), '*/access_tokens' => Http::response($reply, 201)]);
    $this->call('POST', $this->tokenUrl, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}')->assertServiceUnavailable()->assertJsonPath('error.code', 'dependency_token.unavailable')->assertDontSee('ephemeral-sentinel');
})->with(['write', 'extra', 'repository', 'multiple', 'expired', 'long', 'missing']);

it('maps installation JWT and secondary rate limits to unavailable without exposing messages', function (int $status, array $headers, string $message): void {
    Http::fake(['*/installation' => Http::response(['id' => 7, 'message' => $message], $status, $headers)]);
    $this->call('POST', $this->tokenUrl, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}')
        ->assertServiceUnavailable()->assertJsonPath('error.code', 'dependency_token.unavailable')->assertDontSee($message);
})->with([[401, [], 'ephemeral-sentinel'], [429, [], 'ephemeral-sentinel'], [403, ['Retry-After' => '60'], 'ephemeral-sentinel'], [403, [], 'secondary rate limit ephemeral-sentinel']]);
