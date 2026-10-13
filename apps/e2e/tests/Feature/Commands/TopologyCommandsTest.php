<?php

declare(strict_types=1);

use App\Console\Commands\Topology\AcquireCommand;
use App\Console\Commands\Topology\ExecCommand;
use App\Console\Commands\Topology\KillCommand;
use App\Console\Commands\Topology\LogsCommand;
use App\Console\Commands\Topology\ReleaseCommand;
use App\Console\Commands\Topology\ShellCommand;
use App\Console\Commands\Topology\SpawnCommand;
use App\Console\Commands\Topology\StatusCommand;
use App\Console\Commands\Topology\SyncCommand;
use App\Console\Commands\Topology\VerifyCommand;
use App\Console\Commands\Topology\WebCommand;
use App\E2E\IssueState;
use App\E2E\Value\TopologyRecord;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\OperationId;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\VerificationReport;
use App\E2E\WorktreeLocator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeIncus;

/**
 * A primary checkout with one registered issue worktree on the branch `<issue>-feature`.
 *
 * @return array{primary:string,worktree:string}
 */
function commandPrimaryFixture(string $issue = 'TST-12'): array
{
    $primary = temporaryPath('orbit-command-primary-', 6);
    $worktree = $primary.'-worktrees/'.strtolower($issue);
    mkdir($primary, 0700, true);
    foreach ([
        ['init', '-q', '-b', 'main'],
        ['-c', 'user.name=Orbit', '-c', 'user.email=orbit@example.test', 'commit', '-q', '--allow-empty', '-m', 'fixture'],
        ['worktree', 'add', '-q', '-b', strtolower($issue).'-feature', $worktree],
    ] as $arguments) {
        new Symfony\Component\Process\Process(['git', ...$arguments], $primary)->mustRun();
    }
    app()->instance(WorktreeLocator::class, new WorktreeLocator($primary));

    return ['primary' => $primary, 'worktree' => (string) realpath($worktree)];
}

/** Records a composed topology of app-dev in the worktree, as `acquire` leaves it. */
function commandTopologyFixture(string $issue, string $worktree, ?TopologyRecipe $recipe = null): TopologyRecord
{
    $recipe ??= TopologyRecipe::compose(['app-dev']);
    $topology = new TopologyRecord(
        topologyConstructionFixture($issue, 'a', $recipe),
        new SourceState(str_repeat('d', 40), str_repeat('d', 40)),
        new VerificationReport(true, ['nodes.registered' => verificationProbeFixture(probe: 'nodes.registered')]),
    );
    $state = IssueState::forWorktree($issue, $worktree);
    $state->writeAttempt($topology->attempt, new OperationId(str_repeat('c', 32)), $recipe);
    $state->writeTopology($topology);

    return $topology;
}

/** The running guests and network of a recorded topology on a fake Incus. */
function commandIncusFixture(TopologyRecord $topology, ?Closure $responder = null): FakeIncus
{
    $incus = new FakeIncus()->install();
    $owner = [
        'user.orbit.e2e.owner' => 'orbit-e2e',
        'user.orbit.e2e.issue' => $topology->target->issue,
        'user.orbit.e2e.attempt' => $topology->attempt->value,
    ];
    foreach ($topology->instances as $node => $instance) {
        $incus->instances[$instance] = [
            'type' => $node === 'operator' ? 'container' : 'virtual-machine',
            'status' => 'Running',
            'config' => $owner,
            'devices' => ['root' => ['type' => 'disk', 'pool' => 'orbit-e2e', 'path' => '/'], 'eth0' => ['type' => 'nic', 'network' => $topology->network]],
        ];
    }
    $incus->networks[$topology->network] = ['config' => ['ipv4.address' => '10.232.2.1/24', ...$owner]];
    if ($responder !== null) {
        $incus->respond($responder);
    }

    return $incus;
}

/** @return array<string, mixed> */
function commandJson(string $command, array $parameters): array
{
    Artisan::call($command, [...$parameters, '--json' => true]);

    return json_decode(trim(Artisan::output()), true, 32, JSON_THROW_ON_ERROR);
}

describe('topology commands', function () {
    it('registers the discovery command family and none of the removed commands', function () {
        $names = array_keys(Artisan::all());
        $harness = array_values(array_filter($names, static fn (string $name): bool => preg_match('/\A(topology|topology-images|topology-snapshot|scenario|legacy):/', $name) === 1));
        sort($harness);

        expect($harness)->toBe([
            'topology-images:build',
            'topology-images:status',
            'topology:acquire',
            'topology:exec',
            'topology:kill',
            'topology:logs',
            'topology:release',
            'topology:shell',
            'topology:spawn',
            'topology:status',
            'topology:sync',
            'topology:verify',
            'topology:web',
        ]);
    });

    it('takes the issue and a Node; only acquire names the worktree as an argument', function () {
        $arguments = static fn (Command $command): array => array_keys($command->getDefinition()->getArguments());
        $options = static fn (Command $command): array => array_keys($command->getDefinition()->getOptions());

        expect($arguments(new AcquireCommand))->toBe(['issue', 'worktree'])
            ->and($options(new AcquireCommand))->toBe(['nodes', 'json'])
            ->and($arguments(new ShellCommand))->toBe(['issue', 'node'])
            ->and($arguments(new ExecCommand))->toBe(['issue', 'node'])
            ->and($options(new ExecCommand))->toBe(['worktree', 'argv', 'argv-file', 'timeout', 'record', 'json'])
            ->and($arguments(new SpawnCommand))->toBe(['issue', 'node', 'name'])
            ->and($options(new SpawnCommand))->toBe(['worktree', 'argv', 'argv-file', 'json'])
            ->and($arguments(new LogsCommand))->toBe(['issue', 'node', 'name'])
            ->and($options(new LogsCommand))->toBe(['worktree', 'since', 'lines', 'record', 'json'])
            ->and($arguments(new KillCommand))->toBe(['issue', 'node', 'name'])
            ->and($options(new SyncCommand))->toBe(['worktree', 'quick', 'json'])
            ->and($options(new ReleaseCommand))->toBe(['worktree', 'json'])
            ->and($options(new ShellCommand))->toBe(['worktree', 'json']);

        foreach ([new SyncCommand, new VerifyCommand, new StatusCommand, new ReleaseCommand, new WebCommand] as $command) {
            expect($arguments($command))->toBe(['issue'])
                ->and($options($command))->toContain('worktree', 'json');
        }
    });

    it('opens the shell with the exec environment and a login shell in the checkout', function () {
        expect(ShellCommand::shellArgv('/home/orbit/orbit'))->toBe([
            'runuser', '-u', 'orbit', '--',
            'env', '-C', '/home/orbit/orbit',
            'HOME=/home/orbit',
            'ORBIT_HOME=/home/orbit/.orbit',
            'DB_DATABASE=/home/orbit/.orbit/gateway.sqlite',
            'bash', '-l',
        ]);
    });

    it('refuses invalid declared Nodes as JSON before it touches Incus', function (string $nodes, string $error) {
        ['worktree' => $worktree] = commandPrimaryFixture();
        $incus = new FakeIncus()->install();

        expect(commandJson('topology:acquire', ['issue' => 'TST-12', 'worktree' => $worktree, '--nodes' => $nodes]))
            ->toBe(['state' => 'failed', 'error' => $error])
            ->and($incus->calls)->toBe([])
            ->and(IssueState::forWorktree('TST-12', $worktree)->hasAttempt())->toBeFalse();
    })->with([
        'unknown Node' => ['app-dev,web', 'A declared Node must be one of app-dev, app-prod, app-prod-2.'],
        'the Gateway' => ['gateway', 'A declared Node must be one of app-dev, app-prod, app-prod-2.'],
        'a repeated Node' => ['app-dev, app-dev', 'The Node [app-dev] is declared twice.'],
        'a second production Node alone' => ['app-dev,app-prod-2', 'The Node [app-prod-2] requires [app-prod].'],
    ]);

    it('refuses a held topology, and with --replace releases it before it acquires', function () {
        ['worktree' => $worktree] = commandPrimaryFixture();
        $topology = commandTopologyFixture('TST-12', $worktree);
        $incus = commandIncusFixture($topology);

        $refused = commandJson('topology:acquire', ['issue' => 'TST-12', 'worktree' => $worktree, '--nodes' => 'app-dev']);
        expect($refused['error'])->toContain('TST-12 already has topology attempt '.$topology->attempt->value.'; release it first, or acquire with --replace.')
            ->and($incus->instances)->toHaveCount(3);

        $replaced = commandJson('topology:acquire', ['issue' => 'TST-12', 'worktree' => $worktree, '--nodes' => 'app-dev', '--replace' => true]);

        expect($replaced['state'])->toBe('failed')
            ->and($replaced['error'])->toContain('run bin/bootstrap in the worktree before acquire')
            ->and($incus->instances)->toBe([])
            ->and($incus->networks)->toBe([])
            ->and(IssueState::forWorktree('TST-12', $worktree)->hasAttempt())->toBeFalse();
    });

    it('rejects unsafe command inputs before infrastructure access', function () {
        Process::fake();

        $this->artisan('topology:exec', ['issue' => 'TST-12', 'node' => 'gateway'])
            ->expectsOutputToContain('argv JSON file')
            ->assertFailed();
        $this->artisan('topology:exec', ['issue' => 'TST-12', 'node' => 'gateway', '--argv' => '["true"]', '--timeout' => '3601'])
            ->expectsOutputToContain('--timeout must be a whole number of seconds from 1 to 3600')
            ->assertFailed();
        $this->artisan('topology:spawn', ['issue' => 'TST-12', 'node' => 'app-dev', 'name' => 'Viewer_1', '--argv' => '["sleep","60"]'])
            ->expectsOutputToContain('The process name must be')
            ->assertFailed();
        $this->artisan('topology:logs', ['issue' => 'TST-12', 'node' => 'app-dev', 'name' => 'viewer', '--lines' => 'all'])
            ->expectsOutputToContain('--lines must be')
            ->assertFailed();
        $this->artisan('topology:status', ['issue' => 'not-an-issue'])
            ->expectsOutputToContain('The topology issue ID is invalid')
            ->assertFailed();
        $this->artisan('topology:acquire', ['issue' => 'TST-12', 'worktree' => 'relative/path'])
            ->expectsOutputToContain('absolute')
            ->assertFailed();
        $this->artisan('topology:exec', ['issue' => 'TST-12', 'node' => 'gateway', '--argv' => '["true"]', '--record' => 'no/slash'])
            ->expectsOutputToContain('The --record label must be 1 to 80')
            ->assertFailed();
        $this->artisan('topology:logs', ['issue' => 'TST-12', 'node' => 'app-dev', 'name' => 'viewer', '--record' => str_repeat('x', 81)])
            ->expectsOutputToContain('The --record label must be 1 to 80')
            ->assertFailed();

        Process::assertNothingRan();
    });

    it('finds the worktree by branch, names a missing one, and refuses two candidates', function () {
        ['primary' => $primary, 'worktree' => $worktree] = commandPrimaryFixture();

        expect(commandJson('topology:status', ['issue' => 'TST-12'])['worktree'])->toBe($worktree);
        $this->artisan('topology:status', ['issue' => 'TST-13'])
            ->expectsOutputToContain('No registered worktree matches TST-13')
            ->assertFailed();

        new Symfony\Component\Process\Process(['git', 'worktree', 'add', '-q', '-b', 'tst-12-other', $primary.'-other'], $primary)->mustRun();
        $this->artisan('topology:status', ['issue' => 'TST-12'])
            ->expectsOutputToContain('More than one worktree matches')
            ->assertFailed();
        expect(commandJson('topology:status', ['issue' => 'TST-12', '--worktree' => $worktree])['worktree'])->toBe($worktree);
    });

    it('reports an absent topology in text and JSON without touching Incus', function () {
        ['worktree' => $worktree] = commandPrimaryFixture();
        $incus = new FakeIncus()->install();

        expect(commandJson('topology:status', ['issue' => 'TST-12']))->toBe(['state' => 'absent', 'issue' => 'TST-12', 'worktree' => $worktree])
            ->and(Artisan::call('topology:status', ['issue' => 'TST-12']))->toBe(0)
            ->and(trim(Artisan::output()))->toBe('absent')
            ->and($incus->calls)->toBe([]);
    });

    it('reports the held topology in text and JSON', function () {
        ['worktree' => $worktree] = commandPrimaryFixture();
        $topology = commandTopologyFixture('TST-12', $worktree);
        $incus = new FakeIncus()->install();

        $status = commandJson('topology:status', ['issue' => 'TST-12']);

        expect(array_keys($status))->toBe(['state', 'issue', 'attempt_id', 'worktree', 'acquired_at', 'nodes', 'topology'])
            ->and($status)->toMatchArray([
                'state' => 'acquired',
                'issue' => 'TST-12',
                'attempt_id' => str_repeat('a', 32),
                'worktree' => $worktree,
                'nodes' => ['gateway', 'app-dev', 'operator'],
                'topology' => $topology->toArray(),
            ])
            ->and($status['acquired_at'])->toMatch('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/')
            ->and(Artisan::call('topology:status', ['issue' => 'TST-12']))->toBe(0)
            ->and(trim(Artisan::output()))->toBe('acquired '.str_repeat('a', 32))
            ->and($incus->calls)->toBe([]);
    });

    it('reports an attempt without a topology record as incomplete', function () {
        ['worktree' => $worktree] = commandPrimaryFixture();
        IssueState::forWorktree('TST-12', $worktree)->writeAttempt(attemptId(), new OperationId(str_repeat('c', 32)), TopologyRecipe::compose([]));

        $status = commandJson('topology:status', ['issue' => 'TST-12']);

        expect($status)->toMatchArray(['state' => 'acquired', 'nodes' => ['gateway', 'operator'], 'topology' => null])
            ->and(Artisan::call('topology:status', ['issue' => 'TST-12']))->toBe(0)
            ->and(trim(Artisan::output()))->toBe('acquired '.str_repeat('a', 32).' incomplete');
    });

    it('executes exact argv as orbit on one declared Node and reports it as JSON', function () {
        ['worktree' => $worktree] = commandPrimaryFixture();
        $topology = commandTopologyFixture('TST-12', $worktree);
        $incus = commandIncusFixture($topology, static fn (string $instance, array $argv): array => ["{\"nodes\":[]}\n", "warning\n", 3]);

        expect(Artisan::call('topology:exec', ['issue' => 'TST-12', 'node' => 'app-dev', '--argv' => '["orbit","node:list","--json"]', '--json' => true]))->toBe(1)
            ->and(json_decode(trim(Artisan::output()), true, 8, JSON_THROW_ON_ERROR))
            ->toBe(['state' => 'executed', 'exit_code' => 3, 'stdout' => "{\"nodes\":[]}\n", 'stderr' => "warning\n"])
            ->and($incus->execs)->toBe([[
                'instance' => $topology->target->instance('app-dev'),
                'argv' => [...GuestCommand::ORBIT_USER_PREFIX, 'orbit', 'node:list', '--json'],
                'stdin' => null,
            ]])
            ->and(commandJson('topology:exec', ['issue' => 'TST-12', 'node' => 'app-prod', '--argv' => '["true"]']))
            ->toBe(['state' => 'failed', 'error' => 'The topology has no Node [app-prod]; it has gateway, app-dev, operator.'])
            ->and($incus->execs)->toHaveCount(1)
            ->and(file_get_contents($worktree.'/.e2e/log'))->toContain('topology:exec node=app-dev exit=3 argv=["orbit","node:list","--json"]');
    });

    it('records exec and logs evidence without changing their output or exit code', function () {
        ['worktree' => $worktree] = commandPrimaryFixture('AUX-8');
        commandIncusFixture(
            commandTopologyFixture('AUX-8', $worktree),
            static fn (string $instance, array $argv): array => in_array('journalctl', $argv, true)
                ? ["2026-09-24T06:40:00.123456+00:00 app-dev bun[42]: joined presence\n", '', 0]
                : ["Bearer secret-token\n", 'failed once', 7],
        );

        $this->artisan('topology:exec', [
            'issue' => 'AUX-8',
            'node' => 'gateway',
            '--argv' => '["orbit","node:list","--password=hunter2"]',
            '--record' => 'node list after crash',
        ])
            ->expectsOutput("Bearer secret-token\n")
            ->assertExitCode(1);
        $this->artisan('topology:logs', ['issue' => 'AUX-8', 'node' => 'app-dev', 'name' => 'viewer', '--record' => 'viewer.log'])
            ->expectsOutput('2026-09-24T06:40:00.123456+00:00 app-dev bun[42]: joined presence')
            ->assertSuccessful();

        $evidence = (string) file_get_contents($worktree.'/.e2e/evidence.log');
        expect(fileperms($worktree.'/.e2e/evidence.log') & 0777)->toBe(0600)
            ->and($evidence)
            ->toMatch('/\A=== \d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z node list after crash node=gateway exit=7 duration=\d+ms end=\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z\n'
                .preg_quote("\$ orbit node:list '--password=[REDACTED]'\n--- stdout\nBearer [REDACTED]\n--- stderr\nfailed once\n\n", '/')
                .'=== \S+ viewer\.log node=app-dev exit=0 duration=\d+ms end=\S+\n'
                .preg_quote("\$ sudo journalctl --no-pager --output=short-iso-precise --unit=orbit-e2e-viewer.service\n"
                    ."--- stdout\n2026-09-24T06:40:00.123456+00:00 app-dev bun[42]: joined presence\n--- stderr\n\n", '/')
                .'\z/')
            ->not->toContain('hunter2', 'secret-token');
    });

    it('refuses web without a topology rather than acquiring one', function () {
        commandPrimaryFixture();
        Process::fake();

        $this->artisan('topology:web', ['issue' => 'TST-12', '--json' => true])->assertFailed();
        Process::assertNothingRan();
    });

    it('releases every guest and the network, then reports the topology absent', function () {
        ['worktree' => $worktree] = commandPrimaryFixture();
        $topology = commandTopologyFixture('TST-12', $worktree);
        $incus = commandIncusFixture($topology);

        $released = commandJson('topology:release', ['issue' => 'TST-12']);

        expect($released)->toBe([
            'state' => 'released',
            'issue' => 'TST-12',
            'attempt_id' => str_repeat('a', 32),
            'released' => [
                'stopped:'.$topology->target->instance('operator'),
                'stopped:'.$topology->target->instance('app-dev'),
                'stopped:'.$topology->target->instance('gateway'),
                'deleted:'.$topology->target->instance('operator'),
                'deleted:'.$topology->target->instance('app-dev'),
                'deleted:'.$topology->target->instance('gateway'),
                'deleted:'.$topology->network,
            ],
            'already_absent' => [],
            'networks_reaped' => [],
        ])
            ->and($incus->instances)->toBe([])
            ->and($incus->networks)->toBe([])
            ->and(commandJson('topology:status', ['issue' => 'TST-12'])['state'])->toBe('absent')
            ->and(file_get_contents($worktree.'/.e2e/log'))->toContain('topology:release attempt='.str_repeat('a', 32).' ok');
    });

    it('prints the released attempt in text and names an absent one', function () {
        ['worktree' => $worktree] = commandPrimaryFixture();
        commandIncusFixture(commandTopologyFixture('TST-12', $worktree));

        $this->artisan('topology:release', ['issue' => 'TST-12'])
            ->expectsOutput('released '.str_repeat('a', 32))
            ->assertSuccessful();
        $this->artisan('topology:release', ['issue' => 'TST-12', '--json' => true])
            ->expectsOutput('{"state":"failed","error":"TST-12 has no active attempt."}')
            ->assertFailed();
        $this->artisan('topology:verify', ['issue' => 'TST-12'])
            ->expectsOutputToContain('TST-12 has no active attempt.')
            ->assertFailed();
        expect(file_get_contents($worktree.'/.e2e/log'))
            ->toContain('topology:release failed: TST-12 has no active attempt.');
    });
});
