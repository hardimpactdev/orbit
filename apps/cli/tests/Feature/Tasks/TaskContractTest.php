<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Saloon\Http\Faking\MockClient;

/**
 * Contract tests replay the recorded tasks responses and compare the complete command output
 * with tests/Expected/tasks. A fixture change that reaches a tasks command fails here first.
 */
beforeEach(function (): void {
    $this->originalColumns = getenv('COLUMNS');
    $this->callerOrbitHome = config('orbit.home');
    putenv('COLUMNS=120');
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app()->forgetInstance(GatewayConfigRepository::class);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    putenv($this->originalColumns === false ? 'COLUMNS' : 'COLUMNS='.$this->originalColumns);
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
    config()->set('orbit.home', $this->callerOrbitHome);
    app()->forgetInstance(GatewayConfigRepository::class);
});

/** @param array<string, mixed> $arguments */
function run_task_contract(string $fixture, string $command, array $arguments, int $exitCode): void
{
    $fixture = Str::after($fixture, 'tasks/');
    [$family, $case] = explode('/', $fixture, 2);

    foreach (['human.txt' => [], 'json' => ['--json' => true]] as $extension => $mode) {
        // A global mock keeps its first responses, so replace it for every replay.
        MockClient::destroyGlobal();
        MockClient::global(gateway_fixture_mock("tasks/{$fixture}"));

        expect(Artisan::call($command, [...$arguments, ...$mode]))->toBe($exitCode);
        expect_output(Artisan::output(), "tasks/{$family}/{$case}.{$extension}");
    }
}

describe('tasks contract', function (): void {
    it('renders a refused check', function (): void {
        run_task_contract('tasks/tasks-publish/not-orchestrated', 'tasks:publish', ['group' => '1', '--check' => '1'], 1);
        run_task_contract('tasks/tasks-check/not-orchestrated', 'tasks:check', ['group' => '1', '--subtask' => '2', '--phase' => 'baseline'], 1);
    });

    it('renders a refused external turn', function (): void {
        run_task_contract('tasks/tasks-turn/not-orchestrated', 'tasks:turn', ['group' => '1', '--subtask' => '2', '--role' => 'implementer', '--prompt' => 'Implement exactly this prompt.', '--thread' => 'new'], 1);
    });

    it('renders native complete analysis outcomes honestly in human and JSON modes', function (string $fixture): void {
        Sleep::fake(syncWithCarbon: true);
        $queued = json_decode(gateway_fixture('tasks/tasks-analyze/queued')['body'], true, flags: JSON_THROW_ON_ERROR)['data']['input'];
        $terminal = json_decode(gateway_fixture($fixture)['body'], true, flags: JSON_THROW_ON_ERROR)['data'];
        $path = $this->orbitHome.'/plan.json';
        file_put_contents($path, json_encode($queued['plan'], JSON_THROW_ON_ERROR));
        $arguments = ['group' => '1', '--subtask' => (string) $queued['subtask'], '--plan' => $path, '--plan-sha256' => $queued['plan_sha256'], '--base' => $queued['base'], '--head' => $queued['head'], '--tree' => $queued['tree'], '--idempotency-key' => 'analysis.c1'];
        try {
            foreach (['human.txt' => [], 'json' => ['--json' => true]] as $extension => $mode) {
                run_contract(['tasks/tasks-analyze/queued', $fixture, 'realtime/realtime-show/unconfigured'], 'tasks:analyze', [...$arguments, ...$mode], $fixture.'.'.$extension, $terminal['status'] === 'failed' ? 1 : 0);
            }
        } finally {
            Sleep::fake(false);
        }
    })->with(['tasks/tasks-analyze/ready', 'tasks/tasks-analyze/action_required', 'tasks/tasks-analyze/blocked', 'tasks/tasks-analyze/changed',
        'tasks/tasks-analyze/failure-overflow', 'tasks/tasks-analyze/failure-unavailable']);

    it('renders the immutable public provision identity without interpreting the pin', function (string $fixture): void {
        run_task_contract($fixture, 'operation:show', ['operation' => '1'], 0);
    })->with(['tasks/tasks-provision/pinned', 'tasks/tasks-provision/unavailable']);

    it('renders the native analysis refusal without generic success', function (): void {
        $input = json_decode(gateway_fixture('tasks/tasks-analyze/queued')['body'], true, flags: JSON_THROW_ON_ERROR)['data']['input'];
        $path = $this->orbitHome.'/plan.json';
        file_put_contents($path, json_encode($input['plan'], JSON_THROW_ON_ERROR));
        run_task_contract('tasks/tasks-analyze/not-orchestrated', 'tasks:analyze', ['group' => '1', '--subtask' => '2', '--plan' => $path, '--plan-sha256' => $input['plan_sha256'], '--base' => $input['base'], '--head' => $input['head'], '--tree' => $input['tree'], '--idempotency-key' => 'analysis.c1'], 1);
    });

    it('renders a refused provision', function (): void {
        run_task_contract('tasks/tasks-provision/not-orchestrated', 'tasks:provision', ['group' => '1'], 1);
    });

    it('renders a declared workload node', function (): void {
        run_task_contract('tasks/tasks-subtask-create/topology', 'tasks:subtask:create', [
            'group' => '1', 'title' => 'Use a workload node', '--brief' => 'Verify the private app-dev node.', '--topology' => '["app-dev"]',
        ], 0);
    });

    it('renders tasks status', function (): void {
        run_task_contract('tasks/tasks-status/enabled', 'tasks:status', [], 0);
        run_task_contract('tasks/tasks-status/assistance', 'tasks:status', [], 0);
        run_task_contract('tasks/tasks-status/workspace-leases', 'tasks:status', [], 0);
        run_task_contract('tasks/tasks-status/merges', 'tasks:status', [], 0);
    });

    it('renders the review-and-merge state of an incoming pull request', function (): void {
        run_task_contract('tasks-show/review-and-merge', 'tasks:show', ['group' => '1'], 0);
    });

    it('renders the group list and an empty list', function (): void {
        run_task_contract('tasks-list/default', 'tasks:list', [], 0);
        run_task_contract('tasks-list/empty', 'tasks:list', [], 0);
    });

    it('renders one group with its brief and subtasks', function (): void {
        run_task_contract('tasks-show/default', 'tasks:show', ['group' => '1'], 0);
    });

    it('renders a watched pull request in the group JSON without changing pr_url', function (): void {
        run_task_contract('tasks-show/watched', 'tasks:show', ['group' => '1'], 0);
    });

    it('renders a created group and a refused todo create', function (): void {
        run_task_contract('tasks-create/created', 'tasks:create', ['title' => 'Add the tasks CLI', '--project' => '1', '--brief' => 'Brief'], 0);
        run_task_contract('tasks/tasks-create/external', 'tasks:create', ['title' => 'External work', '--project' => '1', '--brief' => 'An orchestrator drives it.', '--orchestration' => 'external'], 0);
        run_task_contract('tasks-create/no-subtasks', 'tasks:create', ['title' => 'Empty', '--project' => '1', '--brief' => 'No subtasks.', '--status' => 'todo'], 1);
    });

    it('renders group updates, a refused update, cancel, and complete', function (): void {
        run_task_contract('tasks-update/updated', 'tasks:update', ['group' => '1', '--title' => 'Add the tasks command family'], 0);
        run_task_contract('tasks-update/not-in-backlog', 'tasks:update', ['group' => '1', '--title' => 'Too late'], 1);
        run_task_contract('tasks-cancel/cancelled', 'tasks:cancel', ['group' => '1', '--yes' => true], 0);
        run_task_contract('tasks/tasks-complete/completed', 'tasks:complete', ['group' => '2', '--yes' => true], 0);
    });

    it('renders completion of an ended watched pull request and refusal of an open one', function (): void {
        run_task_contract('tasks/tasks-complete/ended', 'tasks:complete', ['group' => '1', '--yes' => true], 0);
        run_task_contract('tasks/tasks-complete/not-ready', 'tasks:complete', ['group' => '1', '--yes' => true], 1);
    });

    it('renders subtask create, update, and destroy', function (): void {
        if (! is_dir($this->orbitHome)) {
            mkdir($this->orbitHome, 0700, true);
        }
        $deliverables = $this->orbitHome.'/deliverables.json';
        file_put_contents($deliverables, json_encode([
            ['id' => 'cli-page', 'type' => 'file', 'description' => 'Document every tasks command', 'path' => 'docs/cli/tasks.mdx', 'change' => 'modified'],
            ['id' => 'docs-lint', 'type' => 'command', 'description' => 'The documentation lint passes', 'command' => 'composer docs-lint'],
        ], JSON_THROW_ON_ERROR));

        run_task_contract('tasks-subtask-create/created', 'tasks:subtask:create', ['group' => '1', 'title' => 'Document the commands', '--brief' => 'Add docs/cli/tasks.mdx.', '--deliverables' => $deliverables], 0);
        run_task_contract('tasks-subtask-update/deliverables-locked', 'tasks:subtask:update', ['group' => '1', 'subtask' => '1', '--deliverables' => $deliverables], 1);
        run_task_contract('tasks-subtask-update/updated', 'tasks:subtask:update', ['group' => '1', 'subtask' => '1', '--position' => '2'], 0);
        run_task_contract('tasks-subtask-destroy/destroyed', 'tasks:subtask:destroy', ['group' => '1', 'subtask' => '1', '--yes' => true], 0);
    });

    it('renders a cancelled subtask, a subtask that is not running, and a failed interrupt', function (): void {
        run_task_contract('tasks-subtask-cancel/cancelled', 'tasks:subtask:cancel', ['group' => '1', 'subtask' => '1', '--yes' => true], 0);
        run_task_contract('tasks-subtask-cancel/not-running', 'tasks:subtask:cancel', ['group' => '1', 'subtask' => '1', '--yes' => true], 1);
        run_task_contract('tasks-subtask-cancel/interrupt-failed', 'tasks:subtask:cancel', ['group' => '2', 'subtask' => '3', '--yes' => true], 1);
    });

    it('refuses a deliverables file that is not a list of objects with string fields', function (string $contents): void {
        if (! is_dir($this->orbitHome)) {
            mkdir($this->orbitHome, 0700, true);
        }
        $deliverables = $this->orbitHome.'/deliverables.json';
        file_put_contents($deliverables, $contents);

        foreach (['human.txt' => [], 'json' => ['--json' => true]] as $extension => $mode) {
            expect(Artisan::call('tasks:subtask:update', ['group' => '1', 'subtask' => '1', '--deliverables' => $deliverables, ...$mode]))->toBe(1);
            expect_output(Artisan::output(), "tasks/tasks-subtask-update/deliverables-invalid.{$extension}");
        }
    })->with([
        'an object' => ['{"id":"docs"}'],
        'a list of strings' => ['["docs"]'],
        'a number field' => ['[{"id":"docs","type":"review","description":1}]'],
        'invalid JSON' => ['[{'],
    ]);

    it('renders group assistance comments and refusals', function (): void {
        $request = ['group' => '1', '--type' => 'assistance_requested', '--body' => 'Work item X, publish-retry, cycle 2, gate: may I republish?', '--author' => 'driver'];
        run_task_contract('tasks/tasks-group-comment-create/requested', 'tasks:group:comment:create', $request, 0);
        run_task_contract('tasks/tasks-group-comment-create/resolved', 'tasks:group:comment:create', [...$request, '--type' => 'resolution', '--body' => 'Approved for this work item, stage and cycle.', '--author' => 'nick'], 0);
        run_task_contract('tasks/tasks-group-comment-create/subtask-open', 'tasks:group:comment:create', $request, 1);
        run_task_contract('tasks/tasks-group-comment-create/group-closed', 'tasks:group:comment:create', $request, 1);
        run_task_contract('tasks/tasks-group-comment-list/default', 'tasks:group:comment:list', ['group' => '1'], 0);
        run_task_contract('tasks/tasks-group-comment-list/empty', 'tasks:group:comment:list', ['group' => '1'], 0);
    });

    it('renders comments and agent threads', function (): void {
        run_task_contract('tasks-comment-create/created', 'tasks:comment:create', ['group' => '1', 'subtask' => '1', '--type' => 'resolution', '--body' => 'Use the existing request base.', '--author' => 'nick'], 0);
        run_task_contract('tasks-comment-list/default', 'tasks:comment:list', ['group' => '1', 'subtask' => '1'], 0);
        run_task_contract('tasks-agents/default', 'tasks:agents', ['group' => '1'], 0);
    });

    it('renders a live and an archived agent thread snapshot', function (): void {
        run_task_contract('tasks/tasks-agent-show/default', 'tasks:agent:show', ['group' => '1', 'session' => '3'], 0);
        run_task_contract('tasks/tasks-agent-show/archived', 'tasks:agent:show', ['group' => '1', 'session' => '4'], 0);
        run_task_contract('tasks/tasks-agent-show/delta', 'tasks:agent:show', ['group' => '1', 'session' => '3', '--after-sequence' => 'run-1.7'], 0);
        run_task_contract('tasks/tasks-agent-show/pending', 'tasks:agent:show', ['group' => '1', 'session' => '5'], 0);
    });

    it('renders the question list and an empty list', function (): void {
        run_task_contract('tasks-question-list/default', 'tasks:question:list', [], 0);
        run_task_contract('tasks-question-list/empty', 'tasks:question:list', [], 0);
    });

    it('renders a closed question and a refused close', function (): void {
        $arguments = ['question' => '1', '--status' => 'superseded', '--reason' => 'A later subtask owns the continuation.'];
        run_task_contract('tasks-question-close/closed', 'tasks:question:close', $arguments, 0);
        run_task_contract('tasks-question-close/already-closed', 'tasks:question:close', $arguments, 1);
    });
});
