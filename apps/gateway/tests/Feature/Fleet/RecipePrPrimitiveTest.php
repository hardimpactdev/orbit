<?php

declare(strict_types=1);

use App\Actions\Fleet\MergeNightlyRecipePullRequestsAction;
use App\Actions\Operations\StartOperationAction;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\RoleName;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Infrastructure\MachineSoftware\SnapshotAvailability;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\Fleet\FakeUbuntuSnapshot;
use Tests\Support\Fleet\FleetFixtures;
use Tests\Support\Fleet\RecordingReleaseAlertNotifier;

/** @return array<string, mixed> */
function recipePrMergeEvidence(): array
{
    $before = file_get_contents(resource_path('machine-software/recipe.json'));
    $document = json_decode($before, true, flags: JSON_THROW_ON_ERROR);
    $snapshot = $document['ubuntu']['snapshot'];
    $release = $document['ubuntu']['release'];
    $date = new DateTimeImmutable($snapshot)->modify('+1 day');
    $next = $date->format('Ymd\THis\Z');
    $after = str_replace($snapshot, $next, $before);
    $bot = ['id' => 4243, 'login' => 'orbit-acme[bot]', 'type' => 'Bot'];
    $sha = str_repeat('b', 40);
    $pr = ['number' => 99, 'html_url' => 'https://github.com/hardimpactdev/orbit/pull/99', 'title' => 'Nightly snapshot',
        'state' => 'open', 'draft' => false, 'user' => $bot, 'commits' => 1, 'changed_files' => 1, 'additions' => 1, 'deletions' => 1,
        'head' => ['ref' => 'nightly/recipe-'.$date->format('Ymd'), 'sha' => $sha, 'repo' => ['full_name' => 'hardimpactdev/orbit']],
        'base' => ['ref' => 'main', 'repo' => ['full_name' => 'hardimpactdev/orbit']]];

    return ['pr' => $pr, 'final' => $pr, 'commits' => [['sha' => $sha, 'author' => $bot, 'committer' => ['login' => 'web-flow', 'type' => 'User'], 'commit' => ['committer' => ['name' => 'GitHub', 'email' => 'noreply@github.com'], 'verification' => ['verified' => true, 'reason' => 'valid']], 'parents' => [['sha' => str_repeat('a', 40)]]]],
        'files' => [['filename' => 'apps/gateway/resources/machine-software/recipe.json', 'status' => 'modified', 'additions' => 1, 'deletions' => 1, 'changes' => 2,
            'patch' => "@@ -2,6 +2,6 @@\n     \"ubuntu\": {\n         \"release\": \"{$release}\",\n-        \"snapshot\": \"{$snapshot}\"\n+        \"snapshot\": \"{$next}\"\n     },"]],
        'before' => $before, 'after' => $after,
        'runs' => [['name' => 'Required checks', 'status' => 'completed', 'conclusion' => 'success', 'head_sha' => $sha, 'app' => ['slug' => 'github-actions']]],
        'merge_status' => 200,
    ];
}

function recipePrMergeHttp(array $evidence, array $overrides = [], bool $repeat = false): RecordingReleaseAlertNotifier
{
    app()->instance(SnapshotAvailability::class, new FakeUbuntuSnapshot);
    FleetFixtures::inPlace();
    FleetFixtures::adopt(FleetFixtures::Commit);
    GitHubTestSupport::storeApp();
    config(['orbit.cli_releases.repository' => 'https://github.com/hardimpactdev/orbit', 'orbit.gateway_releases.check' => 'Required checks']);
    $alerts = new RecordingReleaseAlertNotifier;
    app()->instance(ReleaseAlertNotifier::class, $alerts);
    Http::preventStrayRequests();
    $base = 'https://api.github.com/repos/hardimpactdev/orbit';
    Http::fake(array_replace([
        $base.'/installation' => Http::response(['id' => 33]),
        'https://api.github.com/app/installations/33/access_tokens' => Http::response(['token' => 'ghs_nightly_merge']),
        $base.'/pulls?*' => Http::response([$evidence['pr']]),
        $base.'/pulls/99' => $repeat ? Http::response($evidence['pr']) : Http::sequence()->push($evidence['pr'])->push($evidence['final'])->whenEmpty(Http::response($evidence['pr'])),
        $base.'/pulls/99/commits?*' => Http::response($evidence['commits']),
        $base.'/pulls/99/files?*' => Http::response($evidence['files']),
        $base.'/commits/'.$evidence['pr']['head']['sha'].'/check-runs?*' => Http::response(['total_count' => count($evidence['runs']), 'check_runs' => $evidence['runs']]),
        $base.'/contents/apps/gateway/resources/machine-software/recipe.json*' => static fn (Request $request) => Http::response([
            'content' => base64_encode(str_contains($request->url(), 'ref=main') ? ($evidence['main_content'] ?? $evidence['before']) : (str_contains($request->url(), 'ref='.str_repeat('a', 40)) ? $evidence['before'] : $evidence['after'])),
        ]),
        $base.'/pulls/99/merge' => Http::response(['merged' => $evidence['merge_status'] === 200, 'sha' => str_repeat('c', 40), 'message' => 'Merge verdict'], $evidence['merge_status']),
    ], $overrides));

    return $alerts;
}

it('merges only a proven signed App snapshot head with genuine green checks', function (string $fault): void {
    $evidence = recipePrMergeEvidence();
    match ($fault) {
        'signature' => $evidence['commits'][0]['commit']['verification']['verified'] = false,
        'extra-commit' => $evidence['commits'][] = $evidence['commits'][0],
        'spoofed-check' => $evidence['runs'][0]['app']['slug'] = 'spoof',
        'pending' => $evidence['runs'][0]['status'] = 'in_progress',
        'other-author' => $evidence['pr']['user']['login'] = 'other[bot]',
        'changed-head' => $evidence['final']['head']['sha'] = str_repeat('d', 40),
        default => null,
    };
    recipePrMergeHttp($evidence);

    app(MergeNightlyRecipePullRequestsAction::class)->execute(99, 'ghs_nightly_merge', str_repeat('b', 40));

    if ($fault === 'none') {
        Http::assertSent(static fn (Request $request): bool => $request->method() === 'PUT' && str_ends_with($request->url(), '/merge') && $request['sha'] === str_repeat('b', 40));
    } else {
        Http::assertNotSent(static fn (Request $request): bool => str_ends_with($request->url(), '/merge'));
    }
})->with(['none', 'signature', 'extra-commit', 'spoofed-check', 'pending', 'other-author', 'changed-head']);

/** @return array{Operation, Node} */
function recipePrGatewayOperation($test): array
{
    recipePrMergeHttp(recipePrMergeEvidence());
    $gateway = FleetFixtures::node('gateway-recipe', [RoleName::Gateway]);
    $services = FleetFixtures::node('services-recipe');
    $services->accessibleNodes()->attach($gateway);
    app(GatewayServingHost::class)->remember($gateway);
    config(['dependency_detection.services_node_id' => $services->id]);
    $test->withServerVariables(['REMOTE_ADDR' => $services->wireguard_ip]);
    $operation = app(StartOperationAction::class)->execute('fleet.recipe_pr', 'recipe_pr', 99, $gateway->id, [
        'action' => 'merge', 'variant' => 'snapshot', 'date' => now('Europe/Amsterdam')->format('Ymd'), 'pull_number' => 99,
        'head_sha' => str_repeat('b', 40), 'token' => ['$secret' => 'github.app', 'id' => $gateway->id],
    ], 'test', null);
    $test->postJson('/api/v1/operation-executor/claim', ['kind' => 'fleet.recipe_pr', 'worker' => 'recipe-proof'])->assertOk();

    return [$operation, $gateway];
}

it('runs the registered Gateway PR command with a hydrated App token and stores only its reference', function (): void {
    [$operation] = recipePrGatewayOperation($this);
    $run = ['worker' => 'recipe-proof', 'key' => 'recipe-pr', 'transport' => 'gateway', 'name' => 'github.recipe-pr', 'input' => $operation->input];
    foreach (range(1, 2) as $_) {
        $response = $this->postJson('/api/v1/operation-executor/'.$operation->id.'/run', $run)->assertOk()->assertJsonPath('data.completed', true);
        expect($response->getContent())->not->toContain('ghs_nightly_merge');
    }
    $result = $response->json('data');
    $this->postJson('/api/v1/operation-executor/'.$operation->id.'/step', ['worker' => 'recipe-proof', 'key' => 'pr:finish', 'name' => 'recipe-pr', 'status' => 'succeeded', 'result' => $result])->assertOk();
    $this->postJson('/api/v1/operation-executor/'.$operation->id.'/complete', ['worker' => 'recipe-proof', 'status' => 'succeeded', 'result' => $result])->assertOk();
    expect(json_encode($operation->refresh()->toArray()))->not->toContain('ghs_nightly_merge')
        ->and($operation->state['executor_runs']['recipe-pr']['input']['token'])->toBe($operation->input['token']);
    expect(Http::recorded(static fn (Request $request): bool => $request->method() === 'PUT' && str_ends_with($request->url(), '/merge')))->toHaveCount(1);
});

it('refuses another App owner a changed recipe action a Node override and unlisted Gateway commands', function (string $fault): void {
    [$operation, $gateway] = recipePrGatewayOperation($this);
    $run = ['worker' => 'recipe-proof', 'key' => 'recipe-pr', 'transport' => 'gateway', 'name' => 'github.recipe-pr', 'input' => $operation->input];
    match ($fault) {
        'owner' => $run['input']['token']['id'] = $gateway->id + 1,
        'action' => $run['input']['action'] = 'retire',
        'target' => $run['node_id'] = $gateway->id + 1,
        'command' => $run['name'] = 'github.merge',
        'transport' => $run['transport'] = 'agent',
    };
    $this->postJson('/api/v1/operation-executor/'.$operation->id.'/run', $run)->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
    Http::assertNothingSent();
})->with(['owner', 'action', 'target', 'command', 'transport']);
