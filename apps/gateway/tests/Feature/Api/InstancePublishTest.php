<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubAppStore;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationRunner;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStepStatus;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessOutput;
use App\Infrastructure\Processes\ProcessOutputStream;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\TaskVms\TaskVmGit;
use App\Models\Instance;
use App\Models\Operation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Symfony\Component\Process\Process;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\Orb220DeploymentApiFixture;
use Tests\Support\TestOrbitHome;

const PUBLISH_COMMIT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

/** Git is faked at the same ProcessRunner and SshExecutor seams as Tasks publication. */
final class PublishGit implements ProcessRunner, SshExecutor
{
    public ?string $tip = null;

    public bool $ancestor = true;

    public bool $workflows = false;

    public bool $missing = false;

    public bool $pushFails = false;

    public int $pushes = 0;

    /** @var list<RemoteCommand> */
    public array $remote = [];

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->remote[] = $command;
        if (in_array('cat-file', $command->arguments, true)) {
            return new CommandResult($this->missing ? 128 : 0, 'commit', '', 0, false);
        }
        if (in_array('diff-tree', $command->arguments, true)) {
            return new CommandResult($this->missing ? 128 : 0, $this->workflows ? '.github/workflows/check.yml' : '', '', 0, false);
        }
        if ($command->output !== null) {
            ($command->output)(new ProcessOutput(ProcessOutputStream::Stdout, 'PACK'));
        }

        return new CommandResult(0, '', '', 0, false);
    }

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $args = $invocation->arguments;
        $stdout = '';
        $exit = 0;
        if (in_array('init', $args, true)) {
            mkdir(end($args).'/objects', 0700, true);
        }
        if (in_array('ls-remote', $args, true)) {
            $stdout = $this->tip === null ? '' : $this->tip."\trefs/heads/feature/export\n";
        }
        if (in_array('rev-parse', $args, true)) {
            $stdout = $this->tip ?? '';
        }
        if (in_array('cat-file', $args, true)) {
            $stdout = 'commit';
        }
        if (in_array('merge-base', $args, true)) {
            $exit = $this->ancestor ? 0 : 1;
        }
        if (in_array('push', $args, true)) {
            expect(implode(' ', $args))->not->toContain('--force', '+');
            $this->pushes++;
            $exit = $this->pushFails ? 1 : 0;
            if ($exit === 0) {
                $this->tip = PUBLISH_COMMIT;
            }
        }

        return new CommandResult($exit, $stdout, '', 0, false);
    }
}

beforeEach(function (): void {
    enable_operation_queue();
    $this->fixture = Orb220DeploymentApiFixture::create();
    $this->fixture->owner->roles()->update(['role' => RoleName::AppDev]);
    $this->fixture->instance->project->update(['repository_url' => 'https://github.com/acme/shop.git']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip]);
    $this->git = new PublishGit;
    app()->instance(SshExecutor::class, $this->git);
    app()->instance(TaskVmGit::class, new TaskVmGit($this->git, TestOrbitHome::scratch('instance-publish')));
    app(GitHubAppStore::class)->put(GitHubTestSupport::credentials());
    Http::preventStrayRequests();
    $this->prs = [];
    $this->base = 'main';
    $this->githubFails = false;
    $this->workflowGranted = true;
    Http::fake(function (Request $request) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match ($path) {
            '/repos/acme/shop/installation' => Http::response(['id' => 9]),
            '/app/installations/9/access_tokens' => isset($request['permissions']['workflows']) && ! $this->workflowGranted
                ? Http::response(['message' => 'Permission not granted'], 422) : Http::response(['token' => 'ghs_publish'], 201),
            '/repos/acme/shop/pulls/11' => Http::response(['state' => 'open', 'base' => ['ref' => $this->base]]),
            '/repos/acme/shop/pulls' => $this->githubFails ? Http::response([], 500) : ($request->method() === 'GET'
                ? Http::response($this->prs) : Http::response(['number' => 11, 'html_url' => 'https://github.com/acme/shop/pull/11', 'user' => ['login' => 'orbit']], 201)),
            default => throw new RuntimeException('Unexpected GitHub endpoint '.$path),
        };
    });
});

function publish_start(object $test, array $override = [], bool $pr = false, ?string $key = null): TestResponse
{
    return $test->call('POST', '/api/v1/instances/'.$test->fixture->instance->id.'/publish', server: [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', ...($key === null ? [] : ['HTTP_IDEMPOTENCY_KEY' => $key]),
    ], content: json_encode(array_replace(['commit' => PUBLISH_COMMIT, 'branch' => 'feature/export', ...($pr ? ['pull_request' => ['base' => 'main', 'title' => 'Export', 'body' => 'Details']] : [])], $override), JSON_THROW_ON_ERROR));
}

function publish_open_pr(): array
{
    return ['number' => 11, 'html_url' => 'https://github.com/acme/shop/pull/11', 'state' => 'open', 'merged_at' => null];
}

describe('instance publish start', function (): void {
    it('refuses an unknown peer before reading the source or starting an operation', function (): void {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99']);
        publish_start($this)->assertForbidden()->assertJsonPath('error.code', 'peer.identity_unknown');
        expect(Operation::query()->count())->toBe(0)->and($this->git->remote)->toBeEmpty();
    });

    it('returns 422 for a protected branch and starts nothing', function (string $branch): void {
        publish_start($this, ['branch' => $branch])->assertUnprocessable()->assertJsonPath('error.code', 'instance_publish.branch_forbidden');
        expect(Operation::query()->count())->toBe(0)->and($this->git->remote)->toBeEmpty();
    })->with(['main', 'task-19', 'task-handoff-19']);

    it('returns 422 for a production deployment branch in this Project', function (): void {
        $prod = $this->fixture->owner->replicate();
        $prod->name = 'production';
        $prod->wireguard_ip = '10.44.0.222';
        $prod->save();
        $prod->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
        Instance::query()->create(['project_id' => $this->fixture->instance->project_id, 'node_id' => $prod->id, 'name' => 'prod', 'checkout_path' => '/prod', 'branch' => 'main', 'deployment_branch' => 'release']);
        publish_start($this, ['branch' => 'release'])->assertUnprocessable()->assertJsonPath('error.code', 'instance_publish.branch_forbidden');
        expect(Operation::query()->count())->toBe(0);
    });

    it('returns 422 for a production Instance', function (): void {
        $this->fixture->owner->roles()->update(['role' => RoleName::AppProd]);
        publish_start($this)->assertUnprocessable()->assertJsonPath('error.code', 'instance_publish.production_forbidden');
        expect(Operation::query()->count())->toBe(0);
    });

    it('returns 422 for gh_cli source access', function (): void {
        $this->fixture->instance->project->update(['source_access' => 'gh_cli']);
        publish_start($this)->assertUnprocessable()->assertJsonPath('error.code', 'instance_publish.source_access_unsupported');
        expect(Operation::query()->count())->toBe(0);
    });

    it('returns 422 when Workflows write is unavailable', function (): void {
        $this->git->workflows = true;
        $this->workflowGranted = false;
        publish_start($this)->assertUnprocessable()->assertJsonPath('error.code', 'instance_publish.workflows_permission');
        expect(Operation::query()->count())->toBe(0);
    });

    it('returns 422 for a missing source commit', function (): void {
        $this->git->missing = true;
        publish_start($this)->assertUnprocessable()->assertJsonPath('error.code', 'instance_publish.source_unavailable');
        expect(Operation::query()->count())->toBe(0);
    });

    it('returns 422 for invalid input', function (array $input): void {
        publish_start($this, $input)->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        expect(Operation::query()->count())->toBe(0)->and($this->git->remote)->toBeEmpty();
    })->with([
        [['commit' => 'abc']], [['unknown' => true]], [['commit' => str_repeat('A', 40)]], [['branch' => 'bad..branch']],
        [['pull_request' => ['base' => 'main', 'title' => 'Title']]], [['pull_request' => ['base' => 'bad..branch', 'title' => 'Title', 'body' => 'Text']]],
    ]);

    it('replays a key before current domain checks and queues only once', function (): void {
        $id = publish_start($this, key: 'item.publish.c1')->assertAccepted()->json('data.id');
        run_operation_jobs();
        $this->fixture->owner->roles()->update(['role' => RoleName::AppProd]);
        publish_start($this, key: 'item.publish.c1')->assertAccepted()->assertJsonPath('data.id', $id)->assertJsonPath('data.status', 'succeeded');
        expect(Operation::query()->count())->toBe(1)->and($this->git->pushes)->toBe(1);
        publish_start($this, ['branch' => 'different'], key: 'item.publish.c1')->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
    });
});

describe('instance publish execution', function (): void {
    it('pushes from the Gateway mirror with and without a pull request and sends no token to the Instance', function (bool $pr): void {
        publish_start($this, pr: $pr)->assertAccepted()->assertJsonPath('data.kind', 'instance.publish');
        run_operation_jobs();
        $operation = Operation::query()->sole();
        expect($operation->status)->toBe(OperationStatus::Succeeded)->and($operation->result)->toBe([
            'branch' => 'feature/export', 'commit' => PUBLISH_COMMIT, 'pull_request_number' => $pr ? 11 : null, 'pull_request_url' => $pr ? 'https://github.com/acme/shop/pull/11' : null,
        ])->and($this->git->pushes)->toBe(1);
        foreach ($this->git->remote as $command) {
            expect(implode(' ', $command->arguments))->not->toContain('ghs_publish')->and($command->protectedInput?->carriesGitCredential())->not->toBeTrue();
        }
        Http::assertNotSent(fn (Request $request): bool => isset($request['permissions']['workflows']));
    })->with([false, true]);

    it('accepts an empty pull request body', function (): void {
        publish_start($this, ['pull_request' => ['base' => 'main', 'title' => 'Export', 'body' => '']])->assertAccepted();
        run_operation_jobs();
        expect(Operation::query()->sole()->status)->toBe(OperationStatus::Succeeded);
    });

    it('accepts workflow changes with Workflows write', function (): void {
        $this->git->workflows = true;
        publish_start($this)->assertAccepted();
        run_operation_jobs();
        expect(Operation::query()->sole()->status)->toBe(OperationStatus::Succeeded);
        Http::assertSent(fn (Request $request): bool => ($request['permissions']['workflows'] ?? null) === 'write');
    });

    it('fails a divergent branch without pushing', function (): void {
        $this->git->tip = str_repeat('b', 40);
        $this->git->ancestor = false;
        publish_start($this)->assertAccepted();
        run_operation_jobs();
        expect(Operation::query()->sole()->error_code)->toBe('instance_publish.not_fast_forward')->and(Operation::query()->sole()->failed_step)->toBe('push')->and($this->git->pushes)->toBe(0);
    });

    it('succeeds without a push when the branch already points at the commit', function (): void {
        $this->git->tip = PUBLISH_COMMIT;
        publish_start($this)->assertAccepted();
        run_operation_jobs();
        expect(Operation::query()->sole()->status)->toBe(OperationStatus::Succeeded)->and($this->git->pushes)->toBe(0);
    });

    it('fails the push step on a rejected push', function (): void {
        $this->git->pushFails = true;
        publish_start($this)->assertAccepted();
        run_operation_jobs();
        expect(Operation::query()->sole()->error_code)->toBe('instance_publish.push_failed')->and(Operation::query()->sole()->failed_step)->toBe('push');
    });

    it('reuses an open pull request without opening another', function (): void {
        $this->prs = [publish_open_pr()];
        publish_start($this, pr: true)->assertAccepted();
        run_operation_jobs();
        expect(Operation::query()->sole()->result['pull_request_number'])->toBe(11);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/pulls'));
    });

    it('fails the pull request step when an open request has another base', function (): void {
        $this->prs = [publish_open_pr()];
        $this->base = 'release';
        publish_start($this, pr: true)->assertAccepted();
        run_operation_jobs();
        expect(Operation::query()->sole()->error_code)->toBe('instance_publish.pull_request_base_mismatch')->and(Operation::query()->sole()->failed_step)->toBe('pull_request');
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/pulls'));
    });

    it('opens a new request after the last one was closed or merged', function (?string $mergedAt): void {
        $this->prs = [array_replace(publish_open_pr(), ['state' => 'closed', 'merged_at' => $mergedAt])];
        publish_start($this, pr: true)->assertAccepted();
        run_operation_jobs();
        expect(Operation::query()->sole()->status)->toBe(OperationStatus::Succeeded);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/pulls') && $request['base'] === 'main' && $request['title'] === 'Export' && $request['body'] === 'Details');
    })->with([null, '2026-10-22T00:00:00Z']);

    it('fails the pull request step when GitHub fails', function (): void {
        publish_start($this, pr: true)->assertAccepted();
        $this->githubFails = true;
        run_operation_jobs();
        expect(Operation::query()->sole()->error_code)->toBe('instance_publish.github_failed')->and(Operation::query()->sole()->failed_step)->toBe('pull_request');
    });

    it('resumes after a lost create response without opening a second pull request', function (): void {
        publish_start($this, pr: true)->assertAccepted();
        run_operation_jobs();
        $operation = Operation::query()->sole();
        $this->prs = [publish_open_pr()];
        $state = $operation->state;
        unset($state['steps']['pull_request']);
        $operation->update(['status' => OperationStatus::Queued, 'state' => $state, 'finished_at' => null, 'current_step' => 'pull_request']);
        $operation->steps()->where('name', 'pull_request')->update(['status' => OperationStepStatus::Running, 'finished_at' => null]);
        app(OperationRunner::class)->run($operation->id);
        expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded)->and($this->git->pushes)->toBe(1);
        $creates = collect(Http::recorded())->filter(fn (array $record): bool => $record[0]->method() === 'POST' && str_ends_with($record[0]->url(), '/pulls'));
        expect($creates)->toHaveCount(1);
    });
});

describe('Instance publish Fixtures', function (): void {
    it('records the final publication result for CLI replay', function (bool $pr): void {
        $this->freezeTime();
        $this->travelTo(Carbon::parse('2026-10-22T10:00:00Z'));
        $id = publish_start($this, pr: $pr)->assertAccepted()->json('data.id');
        run_operation_jobs();
        $response = $this->getJson('/api/v1/operations/'.$id)->assertOk();
        record_fixture($response, 'instances/instance-publish/'.($pr ? 'with-pr' : 'branch'), ShowOperationRequest::class, 'GET /api/v1/operations/{operation}');
    })->with([false, true]);
});

/** Execute only the Instance-side Git commands locally, without SSH or credentials. */
function publish_local_checkout(object $test): string
{
    $root = TestOrbitHome::scratch('publish-source');
    File::ensureDirectoryExists($root);
    publish_local_git($root, ['init', '--quiet', '--initial-branch=main']);
    publish_local_git($root, ['config', 'user.name', 'Publish test']);
    publish_local_git($root, ['config', 'user.email', 'publish@example.test']);
    $test->fixture->instance->update(['checkout_path' => $root]);
    app()->instance(SshExecutor::class, new class implements SshExecutor
    {
        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            expect($command->protectedInput)->toBeNull();
            $process = new Process($command->arguments);
            $process->run();

            return new CommandResult($process->getExitCode(), $process->getOutput(), $process->getErrorOutput(), 0, false);
        }
    });

    return $root;
}

/** @param list<string> $arguments */
function publish_local_git(string $root, array $arguments): string
{
    return trim(new Process(['git', '-C', $root, ...$arguments])->mustRun()->getOutput());
}

function publish_local_commit(string $root): string
{
    publish_local_git($root, ['add', '--all']);
    publish_local_git($root, ['commit', '--quiet', '--message=Publish fixture']);

    return publish_local_git($root, ['rev-parse', 'HEAD']);
}

describe('Instance publish source inspection with real Git', function (): void {
    it('rejects a full SHA that identifies another object type before recording an operation', function (string $type): void {
        $root = publish_local_checkout($this);
        file_put_contents($root.'/README', 'source');
        publish_local_commit($root);
        $sha = publish_local_git($root, ['rev-parse', $type === 'tree' ? 'HEAD^{tree}' : 'HEAD:README']);

        publish_start($this, ['commit' => $sha])->assertUnprocessable()->assertJsonPath('error.code', 'instance_publish.source_unavailable');
        expect(Operation::query()->count())->toBe(0);
    })->with(['tree', 'blob']);

    it('requires Workflows write for root, added, removed, and merged workflow changes only', function (string $scenario, bool $workflows): void {
        $root = publish_local_checkout($this);
        File::ensureDirectoryExists($root.'/.github/workflows');
        $workflow = $root.'/.github/workflows/check.yml';
        file_put_contents($root.'/README', 'initial');
        if (in_array($scenario, ['root', 'removal', 'merge'], true)) {
            file_put_contents($workflow, 'initial workflow');
        }
        $sha = publish_local_commit($root);
        if ($scenario === 'addition') {
            file_put_contents($workflow, 'added workflow');
            $sha = publish_local_commit($root);
        } elseif ($scenario === 'removal') {
            unlink($workflow);
            $sha = publish_local_commit($root);
        } elseif ($scenario === 'merge') {
            publish_local_git($root, ['checkout', '--quiet', '-b', 'side']);
            file_put_contents($workflow, 'side workflow');
            publish_local_commit($root);
            publish_local_git($root, ['checkout', '--quiet', 'main']);
            file_put_contents($root.'/README', 'main change');
            publish_local_commit($root);
            // The merge differs only from its second parent under workflows.
            publish_local_git($root, ['merge', '--quiet', '--strategy=ours', '--no-edit', 'side']);
            $sha = publish_local_git($root, ['rev-parse', 'HEAD']);
        } elseif ($scenario === 'other-path') {
            File::ensureDirectoryExists($root.'/.github/workflows-other');
            file_put_contents($root.'/.github/workflows-other/check.yml', 'not a workflow');
            $sha = publish_local_commit($root);
        }
        $this->workflowGranted = false;

        $response = publish_start($this, ['commit' => $sha]);
        if ($workflows) {
            $response->assertUnprocessable()->assertJsonPath('error.code', 'instance_publish.workflows_permission');
            expect(Operation::query()->count())->toBe(0);
        } else {
            $response->assertAccepted();
            Http::assertNotSent(fn (Request $request): bool => isset($request['permissions']['workflows']));
        }
    })->with(['root' => ['root', true], 'addition' => ['addition', true], 'removal' => ['removal', true], 'merge' => ['merge', true], 'other-path' => ['other-path', false]]);
});
