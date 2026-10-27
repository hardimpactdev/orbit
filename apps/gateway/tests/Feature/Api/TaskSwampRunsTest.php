<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\SwampRunReader;
use App\Domain\Tasks\TaskExtensionState;
use App\Models\Node;
use App\Models\Task;

beforeEach(function (): void {
    $gateway = $this->markAsGateway(Node::query()->create(['name' => 'swamp-gateway', 'public_ssh_host' => '192.0.2.91', 'status' => LifecycleStatus::Active, 'wireguard_ip' => '10.44.0.91']));
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    app(TaskExtensionState::class)->enable();
    config(['swamp.read_token' => 'reader.test-secret']);
    $this->group = Task::query()->create(['title' => 'Maintenance', 'brief' => 'Update packages.', 'status' => 'running', 'orchestration' => 'external']);
    $this->reader = new class implements SwampRunReader
    {
        public int $calls = 0;

        public bool $fail = false;

        public array $runs = [];

        public function read(int $groupId): array
        {
            $this->calls++;
            if ($this->fail) {
                throw new RuntimeException('Authorization: Bearer reader.test-secret upstream detail');
            }

            return ['runs' => $this->runs, 'truncated' => false];
        }
    };
    app()->instance(SwampRunReader::class, $this->reader);
    $this->url = '/api/v1/task-groups/'.$this->group->id.'/swamp-runs';
});

function swamp_run_fixture(): array
{
    return ['id' => 'run-1', 'workflowName' => 'maintenance-worker', 'status' => 'running', 'startedAt' => '2026-10-27T12:00:00Z',
        'jobs' => [['name' => 'maintenance', 'steps' => [
            ['name' => 'before', 'status' => 'succeeded', 'outputs' => ['changed' => false]],
            ['name' => 'composer', 'status' => 'running'],
            ['name' => 'after', 'status' => 'pending'],
        ]]], 'log' => 'Worker connected.'];
}

it('returns run and step state and output without an agent thread', function (): void {
    $this->reader->runs = [swamp_run_fixture()];
    $this->getJson($this->url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.availability', 'available')
        ->assertJsonPath('data.runs.0.workflow', 'maintenance-worker')
        ->assertJsonPath('data.runs.0.steps.1.status', 'running')
        ->assertJsonPath('data.runs.0.steps.2.status', 'pending')
        ->assertJsonPath('data.runs.0.log', 'Worker connected.');
    expect($this->getJson($this->url)->json('data.runs.0.steps.0.output'))->toContain('"changed": false')
        ->and($this->reader->calls)->toBe(1);
});

it('redacts before bounding and caching output and omits inputs and paths', function (): void {
    $run = swamp_run_fixture();
    $run['inputs'] = ['environment' => ['PRIVATE' => 'not-for-browser']];
    $run['path'] = '/private/run-path';
    $run['jobs'][0]['steps'][0]['outputs'] = ['password' => 'nested-secret', 'text' => 'reader.test-secret', 'ok' => 'yes'];
    $run['jobs'][0]['steps'][1]['error'] = "\033[31mAuthorization: Bearer error-secret\033[0m";
    $run['log'] = "\033[31m".str_repeat('x', 20_000).' password='.str_repeat('S', 20_000)."\nreader.test-secret\033[0m";
    $this->reader->runs = [$run];
    $response = $this->getJson($this->url)->assertOk();
    expect($response->getContent())->not->toContain('nested-secret', 'not-for-browser', '/private/run-path', 'reader.test-secret', 'error-secret', str_repeat('S', 10), '\u001b')
        ->and($response->json('data.runs.0.log_truncated'))->toBeTrue()
        ->and(strlen($response->json('data.runs.0.log')))->toBeLessThanOrEqual(16_384);
    expect($this->getJson($this->url)->getContent())->not->toContain('nested-secret', 'reader.test-secret');
});

it('keeps a stale snapshot through outages and recovers on a later read', function (): void {
    $this->reader->runs = [swamp_run_fixture()];
    $observed = $this->getJson($this->url)->assertOk()->json('data.observed_at');
    $this->travel(6)->seconds();
    $this->reader->fail = true;
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.availability', 'stale')
        ->assertJsonPath('data.observed_at', $observed)->assertJsonPath('data.runs.0.id', 'run-1');
    $this->reader->fail = false;
    $this->reader->runs[0]['status'] = 'succeeded';
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.availability', 'available')->assertJsonPath('data.runs.0.status', 'succeeded');
});

it('distinguishes an empty read from unavailability and expires stale evidence after a day', function (): void {
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.availability', 'available')->assertJsonPath('data.runs', []);
    $this->travel(25)->hours();
    $this->reader->fail = true;
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.availability', 'unavailable')->assertJsonPath('data.observed_at', null)->assertJsonPath('data.runs', []);
});

it('bounds step count and UTF-8 output across a run', function (): void {
    $run = swamp_run_fixture();
    $run['jobs'][0]['steps'] = array_map(static fn (int $i): array => ['name' => 'step-'.$i, 'status' => 'failed', 'error' => str_repeat('界', 10_000)], range(1, 120));
    $this->reader->runs = [$run];
    $response = $this->getJson($this->url)->assertOk()->assertJsonCount(100, 'data.runs.0.steps')->assertJsonPath('data.runs.0.steps_truncated', true);
    $steps = $response->json('data.runs.0.steps');
    expect(array_sum(array_map(static fn (array $step): int => strlen($step['output']), $steps)))->toBeLessThanOrEqual(32_768);
    foreach ($steps as $step) {
        expect(mb_check_encoding($step['output'], 'UTF-8'))->toBeTrue()->and($step['truncated'])->toBeTrue();
    }
});

it('requires Gateway access and the extension and refuses subtasks', function (): void {
    $subtask = Task::query()->create(['parent_id' => $this->group->id, 'position' => 1, 'title' => 'Child', 'brief' => 'Child.']);
    $this->getJson('/api/v1/task-groups/'.$subtask->id.'/swamp-runs')->assertNotFound();
    $this->getJson('/api/v1/task-groups/999999/swamp-runs')->assertNotFound();
    app(TaskExtensionState::class)->disable();
    $this->getJson($this->url)->assertStatus(409)->assertJsonPath('error.code', 'extension.disabled');
    app(TaskExtensionState::class)->enable();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])->getJson($this->url)->assertForbidden();
    expect($this->reader->calls)->toBe(0);
});

it('uses the production binding and reports missing reader configuration without contacting a server', function (): void {
    app()->forgetInstance(SwampRunReader::class);
    config(['swamp.read_token' => '']);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.availability', 'unavailable')->assertJsonPath('data.runs', []);
});
