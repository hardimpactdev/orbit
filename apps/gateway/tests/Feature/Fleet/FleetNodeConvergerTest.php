<?php

declare(strict_types=1);

use App\Data\Fleet\DesiredAgentData;
use App\Data\Fleet\DesiredCliReleaseData;
use App\Data\Fleet\DesiredFleetStateData;
use App\Domain\Fleet\CliReleaseName;
use App\Domain\Fleet\FleetNodeConverger;
use App\Domain\Fleet\FleetNodeOutcome;
use App\Domain\Fleet\FleetNodeVerification;
use App\Domain\Fleet\FootprintArtifactSkipped;
use App\Domain\Fleet\NodeCliState;
use App\Domain\Fleet\NodeFootprint;
use App\Domain\Fleet\NodeFootprintArtifact;
use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Nodes\NodeCliInstallation;
use App\Domain\Nodes\NodeCliInstaller;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Nodes\NodeLocks;
use App\Infrastructure\Nodes\Roles\NodeRoleConvergeLock;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\Fleet\FakeCliReleaseCatalog;
use Tests\Support\Fleet\FakeFootprintArtifact;
use Tests\Support\Fleet\FleetFixtures;

final class FleetConvergerProbe implements NodeReachabilityProbe
{
    /** @param bool|list<bool> $reachable  One answer for every probe, or one per probe in order. */
    public function __construct(public bool|array $reachable = true) {}

    public function degradation(Node $node): ?ExporterDegradationReason
    {
        $reachable = is_array($this->reachable) ? (array_shift($this->reachable) ?? true) : $this->reachable;

        return $reachable ? null : ExporterDegradationReason::Unreachable;
    }
}

final class FleetConvergerCli implements NodeCliInstaller
{
    public int $calls = 0;

    public function __construct(public string $outcome = NodeCliInstallation::Present, public ?ResourceOperationException $failure = null, public int $failures = PHP_INT_MAX) {}

    public string $inspected = 'present';

    public function inspect(Node $node): string
    {
        return $this->inspected;
    }

    public function ensure(Node $node, DesiredCliReleaseData $release): NodeCliInstallation
    {
        $this->calls++;

        if ($this->failure instanceof ResourceOperationException && $this->failures-- > 0) {
            throw $this->failure;
        }

        return new NodeCliInstallation($this->outcome, false, $this->outcome === NodeCliInstallation::Installed ? $release->version : null);
    }
}

final class FleetConvergerVerification implements FleetNodeVerification
{
    public function __construct(public bool $passes = true) {}

    public function baseline(Node $node): array
    {
        return $this->baselineIssues;
    }

    /** @var list<list<string>> */
    public array $tolerated = [];

    /** @var list<string> */
    public array $baselineIssues = [];

    public function verify(Node $node, array $baseline, string $pinnedAgentVersion, array $tolerated = []): array
    {
        $this->tolerated[] = $tolerated;

        return [
            'passed' => $this->passes,
            'new_issues' => $this->passes ? [] : [['code' => 'node.agent_inactive', 'resource_type' => 'node', 'resource_id' => $node->id, 'summary' => 'inactive']],
            'preexisting_issues' => [],
            'agent_version' => $pinnedAgentVersion,
            'presence' => 'matched',
        ];
    }
}

function fleetDesiredState(): DesiredFleetStateData
{
    return new DesiredFleetStateData(
        FleetFixtures::Commit,
        new FakeCliReleaseCatalog()->find(FleetFixtures::Commit, new CliReleaseName(4681)),
        DesiredAgentData::fromDelivery(app(AgentDelivery::class)),
    );
}

/** @param array<string, mixed> $options */
function fleetConverger(FakeAgentCommandRunner $agents, array $options = []): FleetNodeConverger
{
    return new FleetNodeConverger(
        $options['probe'] ?? new FleetConvergerProbe,
        new NodeRoleConvergeLock(app(NodeLocks::class), waitSeconds: 0),
        $options['cli'] ?? new FleetConvergerCli,
        $agents,
        new NodeFootprint(app(AgentDelivery::class), $options['artifacts'] ?? [new FakeFootprintArtifact('caddy', 'c1', changes: false)]),
        $options['verification'] ?? new FleetConvergerVerification,
        app(AgentDelivery::class),
    );
}

/** @return array<string, mixed> */
function selfUpdateResult(string $outcome, ?string $errorCode = null): array
{
    return [
        'outcome' => $outcome, 'report' => ['outcome' => $outcome],
        'cli_version' => '0.4681.0', 'error_code' => $errorCode,
        'exit_code' => $outcome === 'failed' ? 1 : 0, 'stdout' => '', 'stderr' => '',
    ];
}

describe('fleet Node converge', function (): void {
    // cli.install, agent.install, and the agent artifact each take the update lock themselves.
    it('runs the CLI install, self-update, footprint, and verify in that order', function (): void {
        $agents = (new FakeAgentCommandRunner)->answer('cli.self-update', selfUpdateResult('updated'));
        $cli = new FleetConvergerCli;

        $result = fleetConverger($agents, ['cli' => $cli])->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState());

        expect($result->outcome)->toBe(FleetNodeOutcome::Converged)
            ->and($cli->calls)->toBe(1)
            ->and($agents->calls)->toBe([['node' => 'dev', 'command' => 'cli.self-update', 'input' => ['user' => 'orbit', 'downgrade_to' => null]]])
            ->and($result->cliVersion)->toBe('0.4681.0')
            ->and($result->evidence['footprint']['artifacts'])->toBe(['caddy' => 'unchanged'])
            ->and($result->footprintDigest)->toHaveLength(64);
    });

    it('reports unchanged when no step changed anything', function (): void {
        $agents = (new FakeAgentCommandRunner)->answer('cli.self-update', selfUpdateResult('unchanged'));

        expect(fleetConverger($agents)->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState())->outcome)->toBe(FleetNodeOutcome::Unchanged);
    });

    it('counts a fresh CLI install as a change', function (): void {
        $agents = (new FakeAgentCommandRunner)->answer('cli.self-update', selfUpdateResult('unchanged'));

        expect(fleetConverger($agents, ['cli' => new FleetConvergerCli(NodeCliInstallation::Installed)])->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState())->outcome)
            ->toBe(FleetNodeOutcome::Converged);
    });

    it('marks a Node that SSH cannot reach unreachable and runs nothing', function (): void {
        $agents = new FakeAgentCommandRunner;
        $cli = new FleetConvergerCli;

        $result = fleetConverger($agents, ['probe' => new FleetConvergerProbe(false), 'cli' => $cli])->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState());

        expect($result->outcome)->toBe(FleetNodeOutcome::Unreachable)
            ->and($cli->calls)->toBe(0)
            ->and($agents->calls)->toBe([]);
    });

    it('defers a Node whose role lock another operation holds', function (): void {
        $node = FleetFixtures::node('dev', [RoleName::AppDev]);
        $held = app(NodeLocks::class)->lock('node-role:id:'.$node->id, 60);
        $held->get();

        $result = fleetConverger(new FakeAgentCommandRunner)->converge($node, fleetDesiredState());
        $held->release();

        expect($result->outcome)->toBe(FleetNodeOutcome::Deferred)
            ->and($result->errorCode)->toBe('node_role.node_busy');
    });

    it('fails at the self-update step with its exit code and output', function (): void {
        $agents = (new FakeAgentCommandRunner)->answer('cli.self-update', [...selfUpdateResult('failed', 'self_update.checksum_mismatch'), 'stderr' => 'bad checksum']);
        $footprint = new FakeFootprintArtifact('caddy', 'c1');

        $result = fleetConverger($agents, ['artifacts' => [$footprint]])->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState());

        expect($result->outcome)->toBe(FleetNodeOutcome::Failed)
            ->and($result->step)->toBe('self-update')
            ->and($result->errorCode)->toBe('self_update.checksum_mismatch')
            ->and($result->evidence['details']['exit_code'])->toBe(1)
            ->and($result->evidence['details']['stderr'])->toBe('bad checksum')
            ->and($footprint->applied)->toBe([]);
    });

    it('fails at the CLI step and runs nothing after it', function (): void {
        $agents = new FakeAgentCommandRunner;

        $result = fleetConverger($agents, ['cli' => new FleetConvergerCli(failure: new ResourceOperationException('cli.checksum_mismatch', 'mismatch', 502))])
            ->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState());

        expect($result->outcome)->toBe(FleetNodeOutcome::Failed)
            ->and($result->step)->toBe('cli')
            ->and($result->errorCode)->toBe('cli.checksum_mismatch')
            ->and($agents->calls)->toBe([]);
    });

    it('fails at the footprint step with the artifact', function (): void {
        $agents = (new FakeAgentCommandRunner)->answer('cli.self-update', selfUpdateResult('unchanged'));

        $result = fleetConverger($agents, ['artifacts' => [new FakeFootprintArtifact('caddy', 'c1', fails: true)]])
            ->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState());

        expect($result->step)->toBe('footprint')
            ->and($result->evidence['details']['artifact'])->toBe('caddy');
    });

    it('fails when the verify finds a new Doctor issue', function (): void {
        $agents = (new FakeAgentCommandRunner)->answer('cli.self-update', selfUpdateResult('updated'));

        $result = fleetConverger($agents, ['verification' => new FleetConvergerVerification(false)])->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState());

        expect($result->outcome)->toBe(FleetNodeOutcome::Failed)
            ->and($result->step)->toBe('verify')
            ->and($result->errorCode)->toBe('fleet.verify_failed')
            ->and($result->message)->toBe('Doctor reports node.agent_inactive after the rollout.');
    });
});

it('waits without failing when self-update is pending or incomplete', function (string $outcome, string $code): void {
    $agents = (new FakeAgentCommandRunner)->answer('cli.self-update', selfUpdateResult($outcome));
    $footprint = new FakeFootprintArtifact('caddy', 'c1');

    $result = fleetConverger($agents, ['artifacts' => [$footprint]])->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState());

    expect($result->outcome)->toBe(FleetNodeOutcome::Waiting)
        ->and($result->errorCode)->toBe($code)
        ->and($result->outcome->awaitsCatchUp())->toBeTrue()
        ->and($footprint->applied)->toBe([]);
})->with([
    'pending' => ['pending', 'fleet.release_pending'],
    'incomplete' => ['incomplete', 'fleet.self_update_incomplete'],
]);

it('defers a Node whose self-update lock is busy', function (): void {
    $agents = (new FakeAgentCommandRunner)->answer('cli.self-update', selfUpdateResult('failed', 'self_update.busy'));

    $result = fleetConverger($agents)->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState());

    expect($result->outcome)->toBe(FleetNodeOutcome::Deferred)
        ->and($result->errorCode)->toBe('self_update.busy');
});

it('fails with the envelope error code when self-update fails before any step', function (): void {
    $agents = (new FakeAgentCommandRunner)->answer('cli.self-update', selfUpdateResult('failed', 'self_update.lock_failed'));

    $result = fleetConverger($agents)->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState());

    expect($result->outcome)->toBe(FleetNodeOutcome::Failed)
        ->and($result->errorCode)->toBe('self_update.lock_failed');
});

it('defers a Node whose update lock a self-update keeps', function (): void {
    $agents = new FakeAgentCommandRunner;
    $cli = new FleetConvergerCli(failure: new ResourceOperationException('node.update_busy', 'A self-update held the lock.', 409));

    $result = fleetConverger($agents, ['cli' => $cli])->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState());

    expect($result->outcome)->toBe(FleetNodeOutcome::Deferred)
        ->and($result->errorCode)->toBe('node.update_busy')
        ->and($agents->calls)->toBe([]);
});

describe('fleet Node converge resilience', function (): void {
    it('marks a Node unreachable when self-update loses SSH and the Node no longer answers', function (): void {
        $agents = (new FakeAgentCommandRunner)->answer('cli.self-update', static fn (): array => throw new AgentRunnerFailure('cli.self-update', AgentRunnerFailure::Transport, 'Connection closed'));

        $result = fleetConverger($agents, ['probe' => new FleetConvergerProbe([true, false])])->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState());

        expect($result->outcome)->toBe(FleetNodeOutcome::Unreachable)
            ->and($result->step)->toBe('self-update');
    });

    it('retries self-update once when it loses SSH and the Node still answers', function (): void {
        $runs = 0;
        $agents = (new FakeAgentCommandRunner)->answer('cli.self-update', static function () use (&$runs) {
            return ++$runs === 1 ? throw new AgentRunnerFailure('cli.self-update', AgentRunnerFailure::Transport, 'Connection closed') : selfUpdateResult('unchanged');
        });

        $result = fleetConverger($agents)->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState());

        expect($result->outcome)->toBe(FleetNodeOutcome::Unchanged)
            ->and($runs)->toBe(2);
    });

    it('does not retry a definite self-update failure', function (): void {
        $runs = 0;
        $agents = (new FakeAgentCommandRunner)->answer('cli.self-update', static function () use (&$runs) {
            $runs++;

            return selfUpdateResult('failed', 'agent.unhealthy');
        });

        expect(fleetConverger($agents)->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState())->errorCode)->toBe('agent.unhealthy')
            ->and($runs)->toBe(1);
    });

    it('retries a failed CLI install once', function (): void {
        $agents = (new FakeAgentCommandRunner)->answer('cli.self-update', selfUpdateResult('unchanged'));
        $cli = new FleetConvergerCli(failure: new ResourceOperationException('cli.download_failed', 'reset', 502), failures: 1);

        expect(fleetConverger($agents, ['cli' => $cli])->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState())->outcome)->toBe(FleetNodeOutcome::Unchanged)
            ->and($cli->calls)->toBe(2);
    });

    it('defers a Node that a busy lock blocks', function (string $code): void {
        $agents = new FakeAgentCommandRunner;
        $cli = new FleetConvergerCli(failure: new ResourceOperationException($code, 'busy', 409));

        $result = fleetConverger($agents, ['cli' => $cli])->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState());

        expect($result->outcome)->toBe(FleetNodeOutcome::Deferred)
            ->and($result->errorCode)->toBe($code)
            ->and($cli->calls)->toBe(1);
    })->with(['agent.converge_busy', 'agent.converge_lock_lost', 'node.lock_lost', 'node.update_busy']);

    it('leaves a Node with a foreign CLI out of the rollout instead of failing it', function (): void {
        $node = FleetFixtures::node('beast', [RoleName::AppDev]);
        $cli = new FleetConvergerCli(failure: new ResourceOperationException('cli.foreign_binary', 'wrapper', 409));

        $result = fleetConverger(new FakeAgentCommandRunner, ['cli' => $cli])->converge($node, fleetDesiredState());

        expect($result->outcome)->toBe(FleetNodeOutcome::Skipped)
            ->and($result->errorCode)->toBe('cli.foreign_binary')
            ->and(new NodeCliState()->isForeign($node))->toBeTrue()
            ->and($cli->calls)->toBe(1);
    });

    it('re-applies the artifact that repairs an owned issue the baseline shows, though its digest matches', function (): void {
        $node = FleetFixtures::node('dev', [RoleName::AppDev]);
        $agent = new FakeFootprintArtifact('agent', 'a1');
        $caddy = new FakeFootprintArtifact('caddy', 'c1');
        new NodeFootprint(app(AgentDelivery::class), [$agent, $caddy])->converge($node);
        $agent->applied = $caddy->applied = [];
        $verification = new FleetConvergerVerification;
        $verification->baselineIssues = ['node.agent_inactive:node:'.$node->id, 'node.disk_low:node:'.$node->id];
        $agents = (new FakeAgentCommandRunner)->answer('cli.self-update', selfUpdateResult('unchanged'));

        fleetConverger($agents, ['artifacts' => [$agent, $caddy], 'verification' => $verification])->converge($node, fleetDesiredState());

        expect($agent->applied)->toBe(['dev'])
            ->and($caddy->applied)->toBe([]);
    });

    it('lets a Gateway rollback downgrade the CLI to exactly the desired version', function (bool $allowed, FleetNodeOutcome $outcome): void {
        $agents = (new FakeAgentCommandRunner)->answer('cli.self-update', static fn (array $input): array => $input['downgrade_to'] === '0.4681.0' ? selfUpdateResult('updated') : selfUpdateResult('failed', 'self_update.downgrade_refused'));

        $result = fleetConverger($agents)->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState(), $allowed);

        expect($result->outcome)->toBe($outcome)
            ->and(count(array_filter($agents->calls, static fn (array $call): bool => $call['input']['downgrade_to'] === '0.4681.0')))->toBe($allowed ? 1 : 0);
    })->with([
        'a verified release' => [true, FleetNodeOutcome::Converged],
        'anything else' => [false, FleetNodeOutcome::Failed],
    ]);

    it('tolerates Caddy drift from a Caddyfile a user\'s site made unbuildable', function (): void {
        $verification = new FleetConvergerVerification;
        $caddy = new class implements NodeFootprintArtifact
        {
            public function name(): string
            {
                return 'caddy';
            }

            public function applies(Node $node): bool
            {
                return true;
            }

            public function digest(Node $node): string
            {
                return 'code-v1';
            }

            public string $reason = 'caddy_render_refused';

            public function apply(Node $node): ?bool
            {
                throw new FootprintArtifactSkipped($this->reason, 'A site is invalid.');
            }
        };
        $agents = (new FakeAgentCommandRunner)->answer('cli.self-update', selfUpdateResult('unchanged'));

        $result = fleetConverger($agents, ['artifacts' => [$caddy], 'verification' => $verification])->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState());

        expect($result->outcome)->toBe(FleetNodeOutcome::Unchanged)
            ->and($result->evidence['footprint']['artifacts'])->toBe(['caddy' => 'skipped'])
            ->and($result->evidence['footprint']['skipped']['caddy']['reason'])->toBe('caddy_render_refused')
            ->and($verification->tolerated)->toBe([['role.caddy_build_drift']]);

        // A refused validation on the first Node of a rollout is more likely Orbit's own template: it fails.
        $caddy->reason = 'caddy_validate_refused';
        $first = fleetConverger($agents, ['artifacts' => [$caddy]])->converge(FleetFixtures::node('first', [RoleName::AppDev]), fleetDesiredState(), firstVisit: true);
        $later = fleetConverger($agents, ['artifacts' => [$caddy]])->converge(FleetFixtures::node('later', [RoleName::AppDev]), fleetDesiredState(), firstVisit: false);
        $render = (static function () use ($caddy, $agents) {
            $caddy->reason = 'caddy_render_refused';

            return fleetConverger($agents, ['artifacts' => [$caddy]])->converge(FleetFixtures::node('render', [RoleName::AppDev]), fleetDesiredState(), firstVisit: true);
        })();

        expect($first->outcome)->toBe(FleetNodeOutcome::Failed)
            ->and($first->errorCode)->toBe('node.footprint_caddy_failed')
            ->and($later->outcome)->toBe(FleetNodeOutcome::Unchanged)
            ->and($render->outcome)->toBe(FleetNodeOutcome::Unchanged);
    });
});

it('looks at a foreign CLI again only when the Node answers', function (): void {
    $node = FleetFixtures::node('beast', [RoleName::AppDev]);
    new NodeCliState()->markForeign($node);
    $cli = new FleetConvergerCli;
    $cli->inspected = 'missing';

    expect(fleetConverger(new FakeAgentCommandRunner, ['cli' => $cli, 'probe' => new FleetConvergerProbe(false)])->recheckCli($node))->toBeFalse()
        ->and(new NodeCliState()->isForeign($node))->toBeTrue()
        ->and(fleetConverger(new FakeAgentCommandRunner, ['cli' => $cli])->recheckCli($node))->toBeTrue()
        ->and(new NodeCliState()->isForeign($node))->toBeFalse();
});

it('keeps a refused validation skipped on the first Node when Caddy only repaired live drift', function (): void {
    $node = FleetFixtures::node('dev', [RoleName::AppDev]);
    $caddy = new class implements NodeFootprintArtifact
    {
        public int $applied = 0;

        public function name(): string
        {
            return 'caddy';
        }

        public function applies(Node $node): bool
        {
            return true;
        }

        public function digest(Node $node): string
        {
            return 'code-v1';
        }

        public function apply(Node $node): ?bool
        {
            $this->applied++;

            throw new FootprintArtifactSkipped('caddy_validate_refused', 'A user site is invalid.');
        }
    };
    $plan = new NodeFootprint(app(AgentDelivery::class), [$caddy])->expected($node);
    App\Models\NodeFootprint::query()->create(['node_id' => $node->id, 'digest' => $plan->digest(), 'artifacts' => $plan->artifacts, 'converged_at' => now()]);
    $verification = new FleetConvergerVerification;
    $verification->baselineIssues = ['role.caddy_build_drift:node_role:1'];
    $agents = (new FakeAgentCommandRunner)->answer('cli.self-update', selfUpdateResult('unchanged'));

    $result = fleetConverger($agents, ['artifacts' => [$caddy], 'verification' => $verification])->converge($node, fleetDesiredState(), firstVisit: true);

    expect($caddy->applied)->toBe(1)
        ->and($result->outcome)->toBe(FleetNodeOutcome::Unchanged)
        ->and($result->evidence['footprint']['skipped']['caddy']['repair'])->toBeTrue();
});

it('keeps a native CLI admission refusal definite and skips a foreign binary', function (string $state, FleetNodeOutcome $outcome, string $code): void {
    $agents = (new FakeAgentCommandRunner)->refuse('cli.self-update', 'cli.self_update_unavailable', ['state' => $state]);
    $result = fleetConverger($agents)->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState());

    expect($result->outcome)->toBe($outcome)->and($result->errorCode)->toBe($code)
        ->and($agents->calls)->toHaveCount(1);
})->with([
    ['foreign', FleetNodeOutcome::Skipped, 'cli.foreign_binary'],
    ['missing', FleetNodeOutcome::Failed, 'fleet.self_update_failed'],
]);

it('retries a native result without a CLI verdict once and refuses a malformed result', function (bool $malformed): void {
    $agents = (new FakeAgentCommandRunner)->answerMalformed('cli.self-update', $malformed
        ? ['outcome' => 'unknown']
        : [...selfUpdateResult('failed', 'fleet.self_update_failed'), 'report' => null]);
    $result = fleetConverger($agents)->converge(FleetFixtures::node('dev', [RoleName::AppDev]), fleetDesiredState());

    expect($result->outcome)->toBe(FleetNodeOutcome::Failed)->and($result->errorCode)->toBe('fleet.self_update_failed')
        ->and($agents->calls)->toHaveCount(2);
})->with([false, true]);
