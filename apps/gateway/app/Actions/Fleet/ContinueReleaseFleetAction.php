<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Actions\Nodes\StartNodeConvergeAction;
use App\Domain\Fleet\CliConvergePlan;
use App\Domain\Fleet\DesiredFleetState;
use App\Domain\Fleet\FleetReboot;
use App\Domain\Fleet\FleetReleaseLag;
use App\Domain\Fleet\FleetRolloutMembership;
use App\Domain\Fleet\FleetServingRelease;
use App\Domain\Fleet\ReleaseNodeInputs;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayVersion;
use App\Domain\Instances\NestedFleetRefresh;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\AgentReportedVersions;
use App\Models\GatewayRelease;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    /** Seconds before the admission job should continue, or null when it can end. */
    public function execute(GatewayRelease $release): ?int
    {
        return DB::transaction(function () use ($release): ?int {
            $release = GatewayRelease::query()->lockForUpdate()->findOrFail($release->id);
            $phase = ValidatedData::object($release->phases['fleet']);
            if ($phase['status'] === 'current') {
                return null;
            }
            if (! config('fleet.rollout')) {
                $this->save($release, $phase, 'disabled');

                return null;
            }
            if (GatewayRelease::query()->where('outcome', GatewayRelease::Running)->exists()) {
                return 30;
            }
            $root = app(GatewayServingHost::class)->nodeId();
            if ($root !== null) {
                Node::query()->whereKey($root)->lockForUpdate()->firstOrFail();
            }
            if (FleetReboot::open() !== null || AdmitFleetRolloutAction::open()) {
                return 10;
            }

            $members = $this->members();
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
                $baseline = $this->baseline($release, $inputs, $host);
            } catch (Throwable) {
                $this->save($release, [...$phase, 'error_code' => 'fleet.inputs_unavailable'], 'failed');

                return null;
            }
            unset($phase['error_code']);
            foreach ($members as $node) {
                $row = &$rows[$node->id];
                if ($row['status'] === 'current') {
                    continue;
                }
                if (! AdmitFleetRolloutAction::daytime($node) && ! in_array(now('Europe/Amsterdam')->hour, [3, 4, 5], true)) {
                    $row['status'] = 'deferred';

                    continue;
                }
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
                    $delay = $this->visit($release, $node, $row, $inputs, $host, $baseline);
                    if ($delay !== null) {
                        $this->save($release, $phase, 'pending');

                        return $delay;
                    }
                } catch (ResourceOperationException $failure) {
                    if (in_array($failure->errorCode, ['operation.target_busy', 'fleet.rollout_busy'], true)) {
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
            DB::afterCommit(static function () use ($release): void {
                try {
                    app(NestedFleetRefresh::class)->admitAll((string) $release->sha);
                } catch (Throwable) {
                    Log::warning('Could not queue nested-fleet refresh after fleet convergence.', ['release' => $release->id]);
                }
            });

            return null;
        });
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

    /** A pre-feature Node has no host receipt. Compare against the previous sealed release before trusting its baseline. */
    private function baseline(GatewayRelease $release, ReleaseNodeInputs $inputs, string $host): string
    {
        if ($release->previous_release_id === null) {
            return $host;
        }

        return $inputs->host(GatewayReleaseLayout::fromConfig()->releasePath($release->previous_release_id));
    }

    /** @param array<string,mixed> $row */
    private function visit(GatewayRelease $release, Node $node, array &$row, ReleaseNodeInputs $inputs, string $host, string $baseline): ?int
    {
        $recorded = $node->release_inputs ?? [];
        // Keep the original baseline even if a later Gateway release supersedes an unfinished pass.
        if (! isset($recorded['host'])) {
            $node->forceFill(['release_inputs' => [...$recorded, 'host' => $baseline]])->save();
        }
        if (($recorded['host'] ?? $baseline) !== $host) {
            throw new ResourceOperationException('fleet.host_inputs_changed', 'Host-wide inputs require an explicit Node converge.', 409);
        }
        $desired = $inputs->roles($node);
        $applied = (array) ($recorded['roles'] ?? []);
        $changed = array_filter($desired, static fn (array $role, int $id): bool => ($applied[$id] ?? null) !== $role['digest'], ARRAY_FILTER_USE_BOTH);
        $row['roles'] = array_fill_keys(array_column($desired, 'role'), 'unchanged');
        foreach ($changed as $role) {
            $row['roles'][$role['role']] = 'pending';
        }
        $row['status'] = 'running';
        unset($row['error_code']);

        $delivery = app(AgentDelivery::class);
        $reported = app(AgentReportedVersions::class)->get($node->id)['version'] ?? null;
        $pin = $delivery->pin($node);
        $cli = CliConvergePlan::required($node) ? app(DesiredFleetState::class)->current()->cli : null;
        $deliveryOperation = $this->operation($row, 'delivery_operation_id');
        $roleOperation = $this->operation($row, 'role_operation_id');
        if ($deliveryOperation !== null) {
            $observations = (array) ($deliveryOperation->result['nodes'] ?? []);
            $result = ValidatedData::object($observations[0] ?? []);
            $row['agent'] = $result['outcome'] ?? 'pending';
            if ($row['agent'] === 'deferred') {
                if ($deliveryOperation->finished_at?->gt(now()->subSeconds(FleetReleaseLag::RetryAfterSeconds))) {
                    return FleetReleaseLag::RetryAfterSeconds;
                }
                unset($row['delivery_operation_id']);
            } elseif ($deliveryOperation->status !== OperationStatus::Succeeded || ! in_array($row['agent'], ['unchanged', 'converged'], true)
                || ($result['node_id'] ?? null) !== $node->id || ($result['agent_version'] ?? null) !== $delivery->reportedVersion($node)) {
                $row['agent'] = 'failed';
                throw new ResourceOperationException(ValidatedData::nullableString($result['error_code'] ?? null) ?? $deliveryOperation->error_code ?? 'fleet.agent_delivery_failed', 'Agent delivery failed.', 409);
            } else {
                // The fleet run checked the running executable, even if its file already matched.
                $admittedNodes = (array) ($deliveryOperation->input['nodes'] ?? []);
                $admitted = ValidatedData::object($admittedNodes[0] ?? []);
                $plannedCli = ValidatedData::object($admitted['release'] ?? []);
                // A healthy newer CLI or commit build satisfies the admitted pin without replacing it.
                $recorded = [...($node->release_inputs ?? []), 'agent' => $pin,
                    'cli' => isset($result['cli_version']) ? ($plannedCli['version'] ?? null) : null];
                $node->forceFill(['release_inputs' => $recorded])->save();
            }
        }
        if ($roleOperation !== null) {
            if ($roleOperation->status !== OperationStatus::Succeeded) {
                foreach ($changed as $role) {
                    $row['roles'][$role['role']] = 'failed';
                }
                throw new ResourceOperationException($roleOperation->error_code ?? 'fleet.role_converge_failed', 'Role converge failed.', 409);
            }
            $capture = ValidatedData::object($roleOperation->input['_converge']);
            $intent = ValidatedData::object($capture['intent']);
            foreach (ValidatedData::stringList($intent['selected_roles']) as $role) {
                $row['roles'][$role] = 'converged';
            }
        }
        if (($recorded['agent'] ?? null) !== $pin || ($deliveryOperation === null && $reported !== null && $reported !== $delivery->reportedVersion($node))
            || ($cli !== null && (! $cli->isAvailable() || ($recorded['cli'] ?? null) !== $cli->version))) {
            if ($cli !== null && ! $cli->isAvailable()) {
                return 60;
            }
            $row['agent'] = 'pending';
            $row['delivery_operation_id'] = app(AdmitFleetRolloutAction::class)->forRelease($node, (string) $release->sha)->id;

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
