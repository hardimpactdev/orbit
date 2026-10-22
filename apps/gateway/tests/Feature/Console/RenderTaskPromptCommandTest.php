<?php

declare(strict_types=1);

use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\TaskAgentSpawner;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckOutputBuilder;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskDeliverable;
use App\Domain\Tasks\TaskReviewDiff;
use App\Domain\Tasks\TaskReviewPacketBuilder;
use App\Domain\Tasks\TaskWorkspaceMcp;
use App\Models\Instance;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use Illuminate\Support\Arr;
use Symfony\Component\Process\Process;
use Tests\Support\NullTaskReviewDiff;

pest()->group('subprocess');

function render_task_prompt(string $role, array $payload): array
{
    $process = new Process(
        [PHP_BINARY, base_path('artisan'), 'tasks:render-prompt', $role],
        base_path(),
    );
    $process->setInput(json_encode($payload, JSON_THROW_ON_ERROR));
    $process->mustRun();

    /** @var array{role: string, prompt: string, source_commit: string} $output */
    $output = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

    return $output;
}

function production_task_prompt_models(): array
{
    $project = Project::query()->create([
        'name' => 'Gateway prompts',
        'slug' => 'gateway-prompts',
        'repository_url' => 'git@example.test:gateway-prompts.git',
        'default_branch' => 'main',
        'task_check' => 'composer check',
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Offline prompts',
        'brief' => 'Render <info>prompts</info> without <error>a workspace</error>.',
        'status' => 'running',
    ]);
    $task = Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'Render JSON',
        'brief' => 'Read and validate the prompt input.',
        'status' => 'running',
        'deliverables' => [TaskDeliverable::fromArray([
            'id' => 'command',
            'type' => 'command',
            'description' => 'The command renders a prompt.',
            'command' => 'composer test',
            'directory' => 'apps/gateway',
            'fails_on_base' => false,
            'paths' => ['apps/gateway/tests/PromptTest.php'],
        ])->toArray()],
        'subtask_start_commit' => 'abc1234',
    ]);
    $instance = new Instance;
    $instance->starting_commit = str_repeat('b', 40);
    $group->setRelation('project', $project);
    $group->setRelation('taskable', $instance);
    $task->setRelation('parent', $group);

    return [$project, $group, $task];
}

function production_spawner_prompt(string $method): array
{
    [$project, $group, $task] = production_task_prompt_models();
    $spawner = new TaskAgentSpawner(
        new AgentDriverRegistry([]),
        new TaskReviewPacketBuilder(new NullTaskReviewDiff, app(TaskCheckOutputBuilder::class)),
        Mockery::mock(TaskWorkspaceMcp::class),
    );
    $reflection = new ReflectionMethod(TaskAgentSpawner::class, $method);
    $reflection->setAccessible(true);
    $prompt = $reflection->invoke($spawner, $group, $task, 31);

    return [
        'prompt' => $prompt,
        'group' => [
            'id' => $group->id,
            'title' => $group->title,
            'brief' => $group->brief,
            'project_slug' => $project->slug,
            'project_id' => $project->id,
            'default_branch' => $project->default_branch,
            'task_check' => $project->taskCheckCommand(),
            'start_commit' => str_repeat('b', 40),
        ],
        'subtask' => [
            'id' => $task->id,
            'title' => $task->title,
            'brief' => $task->brief,
            'position' => $task->position,
            'deliverables' => $task->deliverables,
        ],
    ];
}

function render_task_prompt_group(): array
{
    return [
        'id' => 17,
        'title' => 'Offline prompts',
        'brief' => 'Render <info>prompts</info> without <error>a workspace</error>.',
        'project_slug' => 'gateway',
        'project_id' => 23,
        'default_branch' => 'main',
        'task_check' => 'composer check',
        'start_commit' => str_repeat('b', 40),
    ];
}

function render_task_prompt_subtask(): array
{
    return [
        'id' => 29,
        'title' => 'Render JSON',
        'brief' => 'Read and validate the prompt input.',
        'position' => 1,
        'deliverables' => [
            TaskDeliverable::fromArray([
                'id' => 'command',
                'type' => 'command',
                'description' => 'The command renders a prompt.',
                'command' => 'composer test',
                'directory' => 'apps/gateway',
                'fails_on_base' => false,
                'paths' => ['apps/gateway/tests/PromptTest.php'],
            ])->toArray(),
        ],
    ];
}

function render_task_prompt_review_packet(bool $continued = false): array
{
    $evidence = [
        'diff' => [['status' => 'modified', 'path' => 'app/Prompt.php']],
        'commands' => (object) ['command' => ['exit_code' => 0, 'output' => '']],
    ];

    return [
        'opens_pull_request' => false,
        'earlier_approved_subtasks' => [],
        'diff_files' => [
            ['path' => 'app/Prompt.php', 'insertions' => 2, 'deletions' => 1],
            ['path' => 'app/NewPrompt.php', 'insertions' => 1, 'deletions' => 0],
        ],
        'files_complete' => ! $continued,
        'diff_available' => ! $continued,
        'diff_summary' => ['files' => 2, 'insertions' => 3, 'deletions' => 1],
        'diff_body' => $continued ? '' : "diff --git a/app/Prompt.php b/app/Prompt.php\n+<info>prompt</info>\n",
        'untracked_content' => $continued ? [] : [['path' => 'app/NewPrompt.php', 'patch' => "diff --git /dev/null b/app/NewPrompt.php\n+<error>new</error>\n"]],
        'handoff_check' => ['status' => 'passed', 'exit_code' => 0, 'evidence' => $evidence],
        'start_commit' => 'abc1234',
        'held_resolution' => null,
    ];
}

function production_review_prompt(bool $continued): array
{
    [$project, $group, $task] = production_task_prompt_models();
    $group->update(['pr_url' => 'https://example.test/pull/1']);
    $instance = new Instance;
    $instance->starting_commit = str_repeat('b', 40);
    $group->setRelation('project', $project);
    $group->setRelation('taskable', $instance);
    $task->setRelation('parent', $group);
    TaskCheck::query()->create([
        'task_id' => $task->id,
        'kind' => TaskCheckKind::Handoff,
        'status' => TaskCheckStatus::Passed,
        'pid' => 1,
        'process_started' => 'Wed Sep 23 12:00:00 2026',
        'head_before' => str_repeat('a', 40),
        'tree_before' => str_repeat('b', 40),
        'exit_code' => 0,
        'deliverable_evidence' => [
            'diff' => [['status' => 'modified', 'path' => 'app/Prompt.php']],
            'commands' => ['command' => ['exit_code' => 0, 'output' => '']],
        ],
        'started_at' => now(),
    ]);
    $diff = new class($continued) implements TaskReviewDiff
    {
        public function __construct(private bool $continued) {}

        public function read(Instance $instance, string $startCommit): array
        {
            return [
                'files' => $this->continued ? [] : [
                    ['path' => 'app/Prompt.php', 'insertions' => 2, 'deletions' => 1],
                    ['path' => 'app/NewPrompt.php', 'insertions' => 1, 'deletions' => 0],
                ],
                'diff' => $this->continued ? '' : "diff --git a/app/Prompt.php b/app/Prompt.php\n+<info>prompt</info>\n"."diff --git /dev/null b/app/NewPrompt.php\n+<error>new</error>\n"."\n",
                'files_complete' => ! $this->continued,
                'diff_available' => ! $this->continued,
                'summary' => ['files' => 2, 'insertions' => 3, 'deletions' => 1],
            ];
        }
    };
    $prompt = (new TaskReviewPacketBuilder($diff, app(TaskCheckOutputBuilder::class)))->build($task, $continued, 32);
    $packet = render_task_prompt_review_packet($continued);
    $packet['opens_pull_request'] = false;

    return [
        'prompt' => $prompt,
        'payload' => [
            'group' => [
                'id' => $group->id,
                'title' => $group->title,
                'brief' => $group->brief,
                'project_slug' => $project->slug,
                'project_id' => $project->id,
                'default_branch' => $project->default_branch,
                'task_check' => $project->taskCheckCommand(),
                'start_commit' => str_repeat('b', 40),
            ],
            'subtask' => [
                'id' => $task->id,
                'title' => $task->title,
                'brief' => $task->brief,
                'position' => $task->position,
                'deliverables' => $task->deliverables,
            ],
            'thread_id' => 32,
            'review_packet' => $packet,
        ],
    ];
}

it('renders the same prompt for the implementer with optional command fields, including explicit false', function (): void {
    $production = production_spawner_prompt('implementerPrompt');
    $deliverable = $production['subtask']['deliverables'][0];
    $payload = [
        'group' => $production['group'],
        'subtask' => $production['subtask'],
        'thread_id' => 31,
    ];
    $prompt = render_task_prompt('implementer', $payload)['prompt'];

    $groupStart = str_repeat('b', 40);

    expect($deliverable['fails_on_base'])->toBeFalse()
        ->and($deliverable['paths'])->toBe(['apps/gateway/tests/PromptTest.php'])
        ->and($prompt)->toBe($production['prompt'])
        ->and($prompt)->toContain("The group started at {$groupStart}.\ngit diff --stat {$groupStart}..HEAD")
        ->and($prompt)->toContain("Feature: {$production['group']['title']}\n\nGroup brief\n{$production['group']['brief']}\n\nOrbit subtask #")
        ->and($prompt)->toContain('Follow this repository\'s task instructions.')
        ->and($prompt)->not->toContain('feature\'s contract')
        ->and($prompt)->not->toContain('Build to them.');
});

it('renders only the configured or absent Project check in implementer prompts', function (?string $check, string $expected): void {
    $group = render_task_prompt_group();
    $group['task_check'] = $check;
    $prompt = render_task_prompt('implementer', [
        'group' => $group,
        'subtask' => render_task_prompt_subtask(),
        'thread_id' => 31,
    ])['prompt'];

    expect($prompt)->toContain($expected)
        ->toContain('Follow this repository\'s task instructions.')
        ->not->toContain('composer check')
        ->not->toContain('vendor/bin/pest')
        ->not->toContain('Routes and publications')
        ->not->toContain('required CLI confirmation flags')
        ->not->toContain('lease and cleanup rules')
        ->not->toContain('feature\'s contract');
})->with([
    'configured' => ['vp run check', 'Orbit runs vp run check when you hand off.'],
    'absent' => [null, 'When the brief is complete, end your turn'],
]);

it('renders the same prompt for the opening reviewer', function (): void {
    $production = production_review_prompt(continued: false);
    $payload = $production['payload'];
    $prompt = render_task_prompt('reviewer', $payload)['prompt'];

    $groupStart = str_repeat('b', 40);

    expect($prompt)->toBe($production['prompt'])
        ->toContain('git diff --stat abc1234;')
        ->toContain("The group started at {$groupStart}.\ngit diff --stat {$groupStart}..HEAD")
        ->toContain('The Project task check is `composer check`.')
        ->not->toContain('feature\'s contract');
});

it('renders only the configured or absent Project check in opening reviewer prompts', function (?string $check, string $expected): void {
    $group = render_task_prompt_group();
    $group['task_check'] = $check;
    $prompt = render_task_prompt('reviewer', [
        'group' => $group,
        'subtask' => render_task_prompt_subtask(),
        'thread_id' => 32,
        'review_packet' => render_task_prompt_review_packet(),
    ])['prompt'];

    expect($prompt)->toContain($expected)
        ->not->toContain('composer check')
        ->not->toContain('vendor/bin/pest')
        ->not->toContain('Routes and publications')
        ->not->toContain('required CLI confirmation flags')
        ->not->toContain('lease and cleanup rules')
        ->not->toContain('feature\'s contract');
})->with([
    'configured' => ['vp run check', 'The Project task check is `vp run check`.'],
    'absent' => [null, 'Do not re-run the Project task check'],
]);

it('renders the same prompt for a continued reviewer', function (): void {
    $production = production_review_prompt(continued: true);
    $payload = $production['payload'];
    $prompt = render_task_prompt('reviewer-continue', $payload)['prompt'];

    $groupStart = str_repeat('b', 40);

    expect($prompt)->toBe($production['prompt'])
        ->toContain('git diff --stat abc1234;')
        ->toContain("The group started at {$groupStart}.\ngit diff --stat {$groupStart}..HEAD")
        ->toContain('The Project task check is `composer check`.')
        ->not->toContain('feature\'s contract');
});

it('renders only the configured or absent Project check in continued reviewer prompts', function (?string $check, string $expected): void {
    $group = render_task_prompt_group();
    $group['task_check'] = $check;
    $review = render_task_prompt_review_packet(continued: true);
    $prompt = render_task_prompt('reviewer-continue', [
        'group' => $group,
        'subtask' => render_task_prompt_subtask(),
        'thread_id' => 32,
        'review_packet' => $review,
    ])['prompt'];

    expect($prompt)->toContain($expected)
        ->not->toContain('composer check')
        ->not->toContain('vendor/bin/pest')
        ->not->toContain('Routes and publications')
        ->not->toContain('required CLI confirmation flags')
        ->not->toContain('lease and cleanup rules')
        ->not->toContain('feature\'s contract');
})->with([
    'configured' => ['vp run check', 'The Project task check is `vp run check`.'],
    'absent' => [null, 'Do not re-run the Project task check'],
]);

it('omits the group start lines when the workspace commit is missing or not a recorded sha', function (?string $start, string $role): void {
    $group = render_task_prompt_group();
    $group['start_commit'] = $start;
    $payload = ['group' => $group, 'subtask' => render_task_prompt_subtask()];
    if ($role === 'reviewer') {
        $payload['review_packet'] = render_task_prompt_review_packet();
    }
    $prompt = render_task_prompt($role, $payload)['prompt'];

    expect($prompt)->not->toContain('The group started at');
    if ($role === 'implementer') {
        expect($prompt)->not->toContain('..HEAD');
    } else {
        expect($prompt)->toContain('git diff --stat abc1234');
    }
})->with([
    'missing' => [null],
    'abbreviated' => ['abc1234'],
    'invalid' => ['not-a-commit'],
])->with(['implementer', 'reviewer']);

it('renders the blocking rule and follow-up policy for opening and continued reviews but not for implementers', function (string $role): void {
    $sentence = 'Block only for: (a) a defect likely in normal operation (ordinary use, routine deploys, common operator mistakes, normal overlap of scheduled jobs); (b) data loss, a security issue, or a state that no supported command can recover; (c) an unmet brief item or deliverable; (d) a regression of existing behaviour; (e) a breach of the quality bar that the repository\'s instructions define. For each blocking finding, give its file:line, the rule it meets, and what must change; for (a), also name the normal-operation trigger. A finding that needs a crash, a lost reply or a timeout between two specific steps is a follow-up unless a deliverable names that guarantee; do not ask for markers, fences or locks against it; an operator recovery command is enough. List follow-ups under \'Follow-ups\' in the approval; they never block. After two changes_requested on one subtask, only (b), (c) and (d) findings may block; list (a) and (e) findings as follow-ups with file:line.';
    $payload = [
        'group' => render_task_prompt_group(),
        'subtask' => render_task_prompt_subtask(),
        'thread_id' => $role === 'implementer' ? 31 : 32,
    ];
    if ($role !== 'implementer') {
        $payload['review_packet'] = render_task_prompt_review_packet(continued: $role === 'reviewer-continue');
    }
    $prompt = render_task_prompt($role, $payload)['prompt'];
    if ($role === 'implementer') {
        expect($prompt)->not->toContain($sentence);
    } else {
        expect($prompt)->toContain('Do not edit or commit the code; Orbit commits the work itself. '.$sentence.' ');
    }
})->with(['implementer', 'reviewer', 'reviewer-continue']);

it('preserves literal formatter tags in prompt JSON', function (): void {
    $review = render_task_prompt_review_packet();
    $review['diff_body'] = "diff --git a/app/Prompt.php b/app/Prompt.php\n+<info>literal</info>\n";
    $reviewOutput = render_task_prompt('reviewer', [
        'group' => render_task_prompt_group(),
        'subtask' => render_task_prompt_subtask(),
        'thread_id' => 32,
        'review_packet' => $review,
    ]);

    expect($reviewOutput['prompt'])->toContain('<info>literal</info>');
});

it('rejects role-inapplicable and missing nested fields', function (string $role, ?string $missing, string $expected): void {
    $packet = render_task_prompt_review_packet();
    if ($missing !== null) {
        Arr::forget($packet, $missing);
    }
    $process = new Process([PHP_BINARY, base_path('artisan'), 'tasks:render-prompt', $role], base_path());
    $process->setInput(json_encode([
        'group' => render_task_prompt_group(),
        'subtask' => render_task_prompt_subtask(),
        'review_packet' => $packet,
    ], JSON_THROW_ON_ERROR));
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain($expected);
})->with([
    'missing handoff check' => ['reviewer', 'handoff_check', 'review_packet.handoff_check is required.'],
    'missing evidence' => ['reviewer', 'handoff_check.evidence', 'review_packet.handoff_check.evidence is required.'],
    'inapplicable review packet' => ['implementer', null, 'input.review_packet is only valid for reviewer roles.'],
]);

it('refuses an unknown prompt field', function (): void {
    $process = new Process([PHP_BINARY, base_path('artisan'), 'tasks:render-prompt', 'implementer'], base_path());
    $process->setInput(json_encode(['group' => render_task_prompt_group(), 'unexpected' => true], JSON_THROW_ON_ERROR));
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain('input.unexpected is not a recognized field.');
});
