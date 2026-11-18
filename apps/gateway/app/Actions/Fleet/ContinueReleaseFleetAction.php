<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Actions\Nodes\StartNodeConvergeAction;
use App\Data\Fleet\DesiredCliReleaseData;
use App\Domain\Fleet\CliConvergePlan;
use App\Domain\Fleet\DesiredFleetState;
use App\Domain\Fleet\FleetReboot;
use App\Domain\Fleet\FleetReleaseLag;
use App\Domain\Fleet\FleetRolloutMembership;
use App\Domain\Fleet\FleetServingRelease;
use App\Domain\Fleet\ReleaseNodeInputs;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayVersion;
use App\Domain\Instances\NestedFleetRefresh;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Operations\OperationStatus;
use App\Domain\Proof\ProofSeeds;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\AgentReportedVersions;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Models\GatewayRelease;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Advances the serving release's ordered pass by one admission. The existing queue job observes terminal Operations. */
final readonly class ContinueReleaseFleetAction
{
    public function current(): ?GatewayRelease
    {
        $serving = app(FleetServingRelease::class);
        $layout = $serving->read();
        if ($serving->supersedes() || $layout['state'] === FleetServingRelease::Unreadable) {
            return null;
        }
        $commit = $layout['commit'] ?? GatewayVersion::current();
        $release = GatewayRelease::query()->where('sha', $commit)->where('outcome', 'verified')->latest('id')->first();

        return $release !== null && isset($release->phases['fleet']) ? $release : null;
    }

    /** A serving release owns its downstream work until it settles or needs a forward fix. */
    public function pending(string $deployed): ?GatewayRelease
    {
        $release = $this->current();
        if ($release === null || $release->sha !== $deployed) {
            return null;
        }
        $fleet = data_get($release->phases, 'fleet.status');
        $refresh = data_get($release->phases, 'default_refresh.status');
        $seed = data_get($release->phases, 'seed_publication.status');
        if (in_array($fleet, ['pending', 'deferred'], true)
            || (in_array($fleet, ['current', 'disabled'], true) && (in_array($refresh, [null, 'pending'], true)
                || ($refresh === 'current' && in_array($seed, [null, 'pending'], true))))) {
            return $release;
        }

        return null;
    }

    /** Seconds before the admission job should continue, or null when it can end. */
    public function execute(GatewayRelease $release): ?int
    {
        try {
            return app(GatewayReleaseLock::class)->run(function () use ($release): ?int {
                // Resolve the catalog before taking the SQLite writer, while this pass owns the release lock.
                $desired = app(DesiredFleetState::class);
                $cli = config('fleet.rollout') && array_any($this->members(), CliConvergePlan::required(...))
                    ? $desired->current()->cli : null;
                $exhausted = $cli !== null && ! $cli->isAvailable() && $desired->cliFallbackExhausted((string) $release->sha);

                return $this->advance($release, $cli, $exhausted);
            });
        } catch (GatewayReleaseException $busy) {
            if ($busy->errorCode !== 'gateway.release_in_progress') {
                throw $busy;
            }

            return 15;
        }
    }

    private function advance(GatewayRelease $release, ?DesiredCliReleaseData $cli, bool $cliUnavailable): ?int
    {
        $delay = DB::transaction(function () use ($release, $cli, $cliUnavailable): ?int {
            $release = GatewayRelease::query()->lockForUpdate()->findOrFail($release->id);
            if ($this->current()?->id !== $release->id) {
                return null;
            }
            $phase = ValidatedData::object($release->phases['fleet']);
            if (GatewayRelease::query()->where('outcome', GatewayRelease::Running)->exists()) {
                return 30;
            }
            if (($phase['status'] ?? null) === 'current' && (data_get($release->phases, 'default_refresh.status') === 'pending'
                || data_get($release->phases, 'seed_publication.status') === 'pending')) {
                return null;
            }
            $members = $this->members();
            $this->preserveHostBaselines($release->previous_release_id, $members);
            if (! config('fleet.rollout')) {
                $this->save($release, $phase, 'disabled');

                return null;
            }
            $root = app(GatewayServingHost::class)->nodeId();
            if ($root !== null) {
                Node::query()->whereKey($root)->lockForUpdate()->firstOrFail();
            }
            if (FleetReboot::open() !== null || AdmitFleetRolloutAction::open()) {
                return 10;
            }

            $previousRows = (array) ($phase['nodes'] ?? []);
            $rows = [];
            $phase['nodes'] = &$rows;
            foreach ($members as $node) {
                $rows[$node->id] = isset($previousRows[$node->id]) ? ValidatedData::object($previousRows[$node->id])
                    : ['node_id' => $node->id, 'name' => $node->name, 'status' => 'pending'];
            }
            $inputs = app(ReleaseNodeInputs::class);
            try {
                $host = $inputs->host();
            } catch (Throwable) {
                $this->save($release, [...$phase, 'error_code' => 'fleet.inputs_unavailable'], 'failed');

                return null;
            }
            unset($phase['error_code']);
            foreach ($members as $node) {
                $row = &$rows[$node->id];
                if ($row['status'] === 'failed') {
                    foreach (['delivery_operation_id', 'role_operation_id'] as $key) {
                        $previous = $this->operation($row, $key);
                        if ($previous !== null && ($previous->status !== OperationStatus::Succeeded
                            || ($key === 'delivery_operation_id' && ($row['agent'] ?? null) === 'failed'))) {
                            if ($previous->finished_at?->gt(now()->subSeconds(FleetReleaseLag::RetryAfterSeconds))) {
                                return FleetReleaseLag::RetryAfterSeconds;
                            }
                            unset($row[$key]);
                        }
                    }
                }
                try {
                    $delay = $this->visit($release, $node, $row, $inputs, $host, $cli, $cliUnavailable);
                    if ($delay !== null) {
                        $this->save($release, $phase, 'pending');

                        return $delay;
                    }
                } catch (ResourceOperationException $failure) {
                    if (in_array($failure->errorCode, ['operation.target_busy', 'fleet.rollout_busy'], true)) {
                        $row['status'] = 'pending';
                        $this->save($release, $phase, 'pending');

                        return 15;
                    }
                    $row['error_code'] = $failure->errorCode;
                    $row['status'] = 'failed';
                    $this->save($release, $phase, 'failed');

                    return null;
                } catch (Throwable) {
                    $row['error_code'] = 'fleet.inputs_unavailable';
                    $row['status'] = 'failed';
                    $this->save($release, $phase, 'failed');

                    return null;
                }
            }
            if (array_any($rows, static fn (array $row): bool => $row['status'] !== 'current')) {
                $this->save($release, $phase, 'deferred');

                return null;
            }
            $this->save($release, $phase, 'current');

            return null;
        });
        if ($delay !== null) {
            return $delay;
        }
        $current = $this->current();
        if ($current?->id !== $release->id || ! in_array(data_get($current->phases, 'fleet.status'), ['current', 'disabled'], true)) {
            return null;
        }
        $phases = $current->phases;
        $refresh = app(NestedFleetRefresh::class)->advance((string) $current->sha, ValidatedData::object($phases['default_refresh'] ?? []));
        $phases['default_refresh'] = $refresh;
        if ($refresh['status'] === 'current' && data_get($phases, 'seed_publication.status') !== 'current') {
            $phases['seed_publication'] = app(ProofSeeds::class)->releasePublication((string) $current->sha, $refresh['defaults']);
        }
        $current->forceFill(['phases' => $phases])->save();

        return null;
    }

    /** @return list<Node> */
    private function members(): array
    {
        $nodes = app(FleetRolloutMembership::class)->members();
        $id = app(GatewayServingHost::class)->nodeId();
        $gateway = $id === null ? null : Node::query()->with('roles')->find($id);
        if ($gateway !== null && app(ManagedNodeEligibility::class)->allows($gateway)) {
            $nodes[] = $gateway;
        }

        return $nodes;
    }

    /**
     * Seed every missing approval before the verified record can be superseded and before a pass can stop at one Node.
     * A missing previous tree records an unknown approval that requires an explicit converge.
     *
     * @param  list<Node>|null  $members
     */
    public function preserveHostBaselines(?string $previousRelease, ?array $members = null): void
    {
        $members ??= $this->members();
        $missing = array_filter($members, static fn (Node $node): bool => ! array_key_exists('host', $node->release_inputs ?? []));
        if ($missing === []) {
            return;
        }

        try {
            $inputs = app(ReleaseNodeInputs::class);
            $baseline = $inputs->host($previousRelease === null ? null : GatewayReleaseLayout::fromConfig()->releasePath($previousRelease));
        } catch (Throwable) {
            $baseline = null;
        }
        DB::transaction(static function () use ($missing, $baseline): void {
            $locked = Node::query()->whereKey(array_map(static fn (Node $node): int => $node->id, $missing))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($missing as $member) {
                $node = $locked->get($member->id);
                if ($node !== null && ! array_key_exists('host', $node->release_inputs ?? [])) {
                    $node->forceFill(['release_inputs' => [...($node->release_inputs ?? []), 'host' => $baseline]])->save();
                }
                if ($node !== null) {
                    $member->release_inputs = $node->release_inputs;
                }
            }
        });
    }

    /** @param array<string,mixed> $row */
    private function visit(GatewayRelease $release, Node $node, array &$row, ReleaseNodeInputs $inputs, string $host, ?DesiredCliReleaseData $cli, bool $cliUnavailable): ?int
    {
        $recorded = $node->release_inputs ?? [];
        if (($recorded['host'] ?? null) !== $host) {
            throw new ResourceOperationException('fleet.host_inputs_changed', 'Host-wide inputs require an explicit Node converge.', 409);
        }
        if (! CliConvergePlan::required($node)) {
            $cli = null;
        } elseif ($cli === null) {
            // Membership changed during catalog preflight. The next visit resolves the pin before admission.
            return 10;
        }
        $desired = $inputs->roles($node);
        $applied = (array) ($recorded['roles'] ?? []);
        $changed = array_filter($desired, static fn (array $role, int $id): bool => ($applied[$id] ?? null) !== $role['digest'], ARRAY_FILTER_USE_BOTH);
        $delivery = app(AgentDelivery::class);
        $pin = $delivery->pin($node);
        $deliveryOperation = $this->operation($row, 'delivery_operation_id');
        $roleOperation = $this->operation($row, 'role_operation_id');
        $reported = app(AgentReportedVersions::class)->get($node->id);
        $drifted = $reported !== null && $reported['version'] !== $delivery->reportedVersion($node)
            && ($deliveryOperation?->finished_at === null || $reported['at'] > (float) $deliveryOperation->finished_at->format('U.u'));
        $needsDelivery = static fn (array $applied): bool => ($applied['agent'] ?? null) !== $pin || $drifted
            || ($cli !== null && (! $cli->isAvailable() || ($applied['cli'] ?? null) !== $cli->version));

        if ($row['status'] === 'current' && $changed === [] && ! $needsDelivery($recorded)) {
            return null;
        }
        if ($row['status'] === 'current') {
            // A previously completed visit cannot certify this new work.
            unset($row['delivery_operation_id'], $row['role_operation_id'], $row['agent']);
            $deliveryOperation = $roleOperation = null;
        }
        if (! AdmitFleetRolloutAction::daytime($node) && ! in_array(now('Europe/Amsterdam')->hour, [3, 4, 5], true)) {
            $row['status'] = 'deferred';

            return null;
        }
        $row['roles'] = array_fill_keys(array_column($desired, 'role'), 'unchanged');
        foreach ($changed as $role) {
            $row['roles'][$role['role']] = 'pending';
        }
        $row['status'] = 'running';
        unset($row['error_code']);

        if ($deliveryOperation !== null) {
            $observations = (array) ($deliveryOperation->result['nodes'] ?? []);
            $result = ValidatedData::object($observations[0] ?? []);
            $row['agent'] = $result['outcome'] ?? 'pending';
            if ($row['agent'] === 'deferred') {
                if ($deliveryOperation->finished_at?->gt(now()->subSeconds(FleetReleaseLag::RetryAfterSeconds))) {
                    return FleetReleaseLag::RetryAfterSeconds;
                }
                unset($row['delivery_operation_id']);
            } else {
                $admittedNodes = (array) ($deliveryOperation->input['nodes'] ?? []);
                $admitted = ValidatedData::object($admittedNodes[0] ?? []);
                $plannedAgent = ValidatedData::object($admitted['agent'] ?? []);
                $plannedCli = ValidatedData::object($admitted['release'] ?? []);
                if ($deliveryOperation->status !== OperationStatus::Succeeded || ! in_array($row['agent'], ['unchanged', 'converged'], true)
                    || ($result['node_id'] ?? null) !== $node->id || ! isset($plannedAgent['sha256'], $plannedAgent['version'])
                    || ($result['agent_version'] ?? null) !== $plannedAgent['version']) {
                    $row['agent'] = 'failed';
                    throw new ResourceOperationException(ValidatedData::nullableString($result['error_code'] ?? null) ?? $deliveryOperation->error_code ?? 'fleet.agent_delivery_failed', 'Agent delivery failed.', 409);
                }
                // Credit the pins this operation actually checked. The desired CLI can change while it runs.
                $locked = Node::query()->whereKey($node->id)->lockForUpdate()->firstOrFail();
                $recorded = [...($locked->release_inputs ?? []), 'agent' => $plannedAgent['sha256'],
                    'cli' => isset($result['cli_version']) ? ($plannedCli['version'] ?? null) : null];
                $locked->forceFill(['release_inputs' => $recorded])->save();
            }
        }
        if ($roleOperation !== null) {
            if ($roleOperation->status !== OperationStatus::Succeeded) {
                foreach ($changed as $role) {
                    $row['roles'][$role['role']] = 'failed';
                }
                throw new ResourceOperationException($roleOperation->error_code ?? 'fleet.role_converge_failed', 'Role converge failed.', 409);
            }
            if (($roleOperation->result['t3_restart_deferred'] ?? []) !== []) {
                $row['status'] = 'pending';
                if ($roleOperation->finished_at?->gt(now()->subSeconds(FleetReleaseLag::RetryAfterSeconds))) {
                    return FleetReleaseLag::RetryAfterSeconds;
                }
            } else {
                $capture = ValidatedData::object($roleOperation->input['_converge']);
                $intent = ValidatedData::object($capture['intent']);
                foreach (ValidatedData::stringList($intent['selected_roles']) as $role) {
                    if (($row['roles'][$role] ?? null) === 'unchanged') {
                        $row['roles'][$role] = 'converged';
                    }
                }
            }
        }
        if ($needsDelivery($recorded)) {
            if ($cli !== null && ! $cli->isAvailable()) {
                if ($cliUnavailable) {
                    throw new ResourceOperationException('fleet.cli_delivery_unavailable', 'No verified CLI delivery is available for the release.', 409);
                }

                return 60;
            }
            $row['agent'] = 'pending';
            $row['delivery_operation_id'] = app(AdmitFleetRolloutAction::class)->forRelease($node, (string) $release->sha, $cli)->id;

            return 10;
        }
        $row['agent'] ??= 'unchanged';
        if ($changed !== []) {
            $row['role_operation_id'] = app(StartNodeConvergeAction::class)->execute($node, roles: array_column($changed, 'role'), release: true)->id;

            return 10;
        }
        $row['status'] = 'current';

        return null;
    }

    /** @param array<string,mixed> $row */
    private function operation(array $row, string $key): ?Operation
    {
        return isset($row[$key]) ? Operation::query()->findOrFail(ValidatedData::integer($row[$key])) : null;
    }

    /** @param array<string,mixed> $phase */
    private function save(GatewayRelease $release, array $phase, string $status): void
    {
        $release->forceFill(['phases' => [...$release->phases, 'fleet' => [...$phase, 'status' => $status]]])->save();
    }
}
