<?php

declare(strict_types=1);

use App\Actions\Fleet\FleetRolloutPlanAction;
use App\Domain\Fleet\DesiredFleetState;
use App\Domain\Fleet\FleetNodeOutcome;
use App\Domain\Fleet\FleetRolloutAlerts;
use App\Domain\Fleet\FleetRolloutStatus;
use App\Domain\Fleet\NodeFootprint;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\FleetRollout;
use App\Models\Node;
use Illuminate\Support\Carbon;
use Orbit\Sdk\Requests\Fleet\ResumeFleetRolloutRequest;
use Orbit\Sdk\Requests\Fleet\ShowFleetRolloutRequest;
use Orbit\Sdk\Requests\Nodes\ConvergeNodeRequest;
use Tests\Support\Fleet\FakeFootprintArtifact;
use Tests\Support\Fleet\FleetFixtures;

/**
 * Records the fleet rollout and node converge responses that the CLI replays (ADR 0202). The Gateway is
 * Node 1, the app-dev Node 2, and the app-prod Node 3; the clock and the request id are fixed.
 */
describe('fleet response fixtures', function (): void {
    beforeEach(function (): void {
        Carbon::setTestNow('2026-10-07T12:00:00Z');
        $gateway = Node::query()->create([
            'name' => 'gateway',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'architecture' => 'x86_64',
            'public_ssh_host' => '192.0.2.2',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.1',
            'ssh_host_fingerprint' => 'SHA256:'.str_repeat('G', 43),
        ]);
        $this->markAsGateway($gateway);
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1']);
        $this->withHeader('X-Orbit-Request-Id', fixture_request_id());
    });

    it('records the rollout status before the first rollout', function (): void {
        FleetFixtures::bind();
        FleetFixtures::node('app-dev', [RoleName::AppDev]);

        record_fixture($this->getJson('/api/v1/fleet/rollout')->assertOk(), 'fleet/fleet-rollout-status/none', ShowFleetRolloutRequest::class, 'GET /api/v1/fleet/rollout');
    });

    it('records a halted rollout, its resume past the failed Node, and a refused resume', function (): void {
        FleetFixtures::bind([new FakeFootprintArtifact('caddy', 'caddy-digest')]);
        FleetFixtures::node('app-dev', [RoleName::AppDev]);
        FleetFixtures::node('app-prod', [RoleName::AppProd]);
        app(FleetRolloutPlanAction::class)->execute();
        $rollout = FleetRollout::query()->sole();
        $failed = $rollout->nodes()->where('node_name', 'app-dev')->sole();
        $failed->update(['outcome' => FleetNodeOutcome::Failed, 'step' => 'self-update', 'error_code' => 'fleet.self_update_failed',
            'message' => 'self-update failed on app-dev', 'evidence' => ['exit_code' => 1], 'attempts' => 1, 'started_at' => now(), 'finished_at' => now()]);
        $rollout->update(['status' => FleetRolloutStatus::Halted, 'halted_node_id' => $failed->node_id,
            'error_code' => $failed->error_code, 'message' => $failed->message]);
        app(FleetRolloutAlerts::class)->halted($rollout, $failed);

        record_fixture($this->getJson('/api/v1/fleet/rollout')->assertOk(), 'fleet/fleet-rollout-status/halted', ShowFleetRolloutRequest::class, 'GET /api/v1/fleet/rollout');
        record_fixture(
            $this->postJson('/api/v1/fleet/rollout/resume', ['skip' => 'app-dev'])->assertOk()->assertJsonPath('data.rollout.status', 'running'),
            'fleet/fleet-rollout-resume/skipped',
            ResumeFleetRolloutRequest::class,
            'POST /api/v1/fleet/rollout/resume',
        );

        $completed = $rollout->nodes()->where('node_name', 'app-prod')->sole();
        $completed->update(['outcome' => FleetNodeOutcome::Converged, 'cli_version' => app(DesiredFleetState::class)->current()->cli->version,
            'agent_version' => app(AgentDelivery::class)->version(), 'footprint_digest' => app(NodeFootprint::class)->expected($completed->node)->digest(),
            'attempts' => 1, 'started_at' => now(), 'finished_at' => now()]);
        $rollout->update(['status' => FleetRolloutStatus::Completed, 'finished_at' => now()]);

        record_fixture($this->getJson('/api/v1/fleet/rollout')->assertOk()->assertJsonPath('data.status', 'completed'), 'fleet/fleet-rollout-status/completed', ShowFleetRolloutRequest::class, 'GET /api/v1/fleet/rollout');
        record_fixture(
            $this->postJson('/api/v1/fleet/rollout/resume')->assertStatus(409)->assertJsonPath('error.code', 'fleet.rollout_not_halted'),
            'fleet/fleet-rollout-resume/not-halted',
            ResumeFleetRolloutRequest::class,
            'POST /api/v1/fleet/rollout/resume',
        );
        expect(FleetRollout::query()->sole()->nodes()->pluck('outcome')->map(static fn (FleetNodeOutcome $outcome): string => $outcome->value)->all())->toBe(['skipped', 'converged']);
    });

    it('records a queued node converge and an unsupported target refusal', function (): void {
        FleetFixtures::bind([new FakeFootprintArtifact('agent', 'agent-digest', changes: null), new FakeFootprintArtifact('caddy', 'caddy-digest')]);
        $node = FleetFixtures::node('app-dev', [RoleName::AppDev]);
        $operator = FleetFixtures::node('operator', managed: false);

        record_fixture($this->postJson("/api/v1/nodes/{$node->id}/converge")->assertAccepted(), 'nodes/node-converge/queued', ConvergeNodeRequest::class, 'POST /api/v1/nodes/{node}/converge');
        record_fixture(
            $this->postJson("/api/v1/nodes/{$operator->id}/converge")->assertStatus(422)->assertJsonPath('error.code', 'node.converge_unsupported'),
            'nodes/node-converge/unsupported',
            ConvergeNodeRequest::class,
            'POST /api/v1/nodes/{node}/converge',
        );
    });

    it('records synchronous executor convergence that applies and then matches', function (): void {
        FleetFixtures::bind([new FakeFootprintArtifact('agent', 'agent-digest', changes: null), new FakeFootprintArtifact('caddy', 'caddy-digest')]);
        $node = FleetFixtures::node('app-dev', [RoleName::AppDev]);
        config(['dependency_detection.services_node_id' => $node->id]);
        record_fixture($this->postJson("/api/v1/nodes/{$node->id}/converge")->assertOk(), 'nodes/node-converge/applied', ConvergeNodeRequest::class, 'POST /api/v1/nodes/{node}/converge');
        record_fixture($this->postJson("/api/v1/nodes/{$node->id}/converge")->assertOk()->assertJsonPath('data.changed', false), 'nodes/node-converge/unchanged', ConvergeNodeRequest::class, 'POST /api/v1/nodes/{node}/converge');
    });

    it('requires an active WireGuard peer', function (): void {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9']);

        $this->getJson('/api/v1/fleet/rollout')->assertForbidden()->assertJsonPath('error.code', 'peer.identity_unknown');
    });
});
