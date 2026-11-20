<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Actions\Nodes\StartNodeConvergeAction;
use App\Data\Fleet\DesiredCliReleaseData;
use App\Domain\Fleet\CliConvergePlan;
use App\Domain\Fleet\CliReleaseUnavailableReason;
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
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Proof\ProofSeeds;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\AgentReportedVersions;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Models\GatewayRelease;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Advances the serving release's ordered pass by one admission. The existing queue job observes terminal Operations.
 *
 * @phpstan-type Legacy array{trees: array<string, string|null>, unreadable: bool, folders: list<string>}
 * @phpstan-type HostCheck array{host: string, accept: bool, known: bool}
 * @phpstan-type RoleInputs array<int, array{role:string,digest:string}>
 * @phpstan-type NodeInputs array{approved: string|null, host: HostCheck|Throwable, roles: RoleInputs|Throwable, gateway: RoleInputs|Throwable}
 * @phpstan-type Prepared array{baselines: array<int, string|null>, legacy: Legacy|null, nodes: array<int, NodeInputs>}
 */
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
        // A release served before the fleet window was removed may still hold `deferred`; continue it. Drop after one release.
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
                // Resolve the catalog and read every release input before taking the SQLite writer, while this pass owns the release lock.
                $members = $this->members();
                $desired = app(DesiredFleetState::class);
                $cli = config('fleet.rollout') && array_any($members, CliConvergePlan::required(...))
                    ? $desired->current()->cli : null;
                $exhausted = $cli !== null && ! $cli->isAvailable()
                    && ($cli->reason === CliReleaseUnavailableReason::ReleaseUnverified || $desired->cliFallbackExhausted((string) $release->sha));

                return $this->advance($release, $cli, $exhausted, $this->prepare($release, $members));
            });
        } catch (GatewayReleaseException $busy) {
            if ($busy->errorCode !== 'gateway.release_in_progress') {
                throw $busy;
            }

            return 15;
        }
    }

    /** @param Prepared $prepared */
    private function advance(GatewayRelease $release, ?DesiredCliReleaseData $cli, bool $cliUnavailable, array $prepared): ?int
    {
        try {
            $delay = DB::transaction(fn (): ?int => $this->pass($release, $cli, $cliUnavailable, $prepared));
        } finally {
            // The release trees read from Git for this pass.
            foreach ($prepared['legacy']['folders'] ?? [] as $folder) {
                File::deleteDirectory($folder);
            }
        }
        if ($delay !== null) {
            return $delay;
        }
        $current = $this->current();
        if ($current?->id !== $release->id || ! in_array(data_get($current->phases, 'fleet.status'), ['current', 'disabled'], true)) {
            return null;
        }
        $previous = ValidatedData::object($current->phases['default_refresh'] ?? []);
        $defaults = (array) ($previous['defaults'] ?? []);
        $refresh = app(NestedFleetRefresh::class)->advance((string) $current->sha, $previous,
            static function (int $id, array $result) use ($current, &$defaults): void {
                if (($defaults[$id] ?? null) === $result) {
                    return;
                }
                $defaults[$id] = $result;
                $current->forceFill(['phases' => [...$current->phases, 'default_refresh' => ['status' => 'pending', 'defaults' => $defaults]]])->save();
            });

        return DB::transaction(function () use ($current, $refresh): ?int {
            // The database writer orders publication and its receipt with the default's seed projection.
            $phases = [...$current->phases, 'default_refresh' => $refresh];
            if ($refresh['status'] === 'current' && data_get($phases, 'seed_publication.status') !== 'current') {
                $phases['seed_publication'] = app(ProofSeeds::class)->releasePublication((string) $current->sha, $refresh['defaults']);
            }
            if ($refresh['status'] === 'disabled' && data_get($phases, 'seed_publication.status') === 'pending') {
                $publication = ValidatedData::object($phases['seed_publication']);
                $phases['seed_publication'] = [...$publication, 'status' => 'disabled'];
            }
            $current->forceFill(['phases' => $phases])->save();

            return $refresh['status'] === 'pending' || data_get($phases, 'seed_publication.status') === 'pending' ? 60 : null;
        });
    }

    /**
     * Reads every file and Git tree the pass needs, outside the SQLite writer: the host baselines of Nodes without one, the
     * legacy release trees, and each member's host and role digests. A failure is kept and surfaces where the pass would have read.
     *
     * @param  list<Node>  $members
     * @return Prepared
     */
    private function prepare(GatewayRelease $release, array $members): array
    {
        $prepared = ['baselines' => $this->hostBaselines($release->previous_release_id, $members), 'legacy' => null, 'nodes' => []];
        if (! config('fleet.rollout')) {
            return $prepared;
        }
        $inputs = app(ReleaseNodeInputs::class);
        try {
            $legacy = $prepared['legacy'] = $this->legacyTrees($release, $inputs, $members);
        } catch (Throwable) {
            return $prepared;
        }
        foreach ($members as $node) {
            $approved = self::legacyApproval($node);
            try {
                $host = $inputs->host(node: $node);
                // A baseline recorded before host inputs were classified by Node approved a release tree. It stands when this Node's
                // own inputs are the same in that tree and in this release. When a release tree cannot be read, nothing can prove a
                // change: the release record and the log name the Node. A Node whose own inputs are all the legacy digest covered
                // needs no tree: the digests compare directly, and a difference is a change. Drop after one release.
                $own = $approved !== null && $inputs->coversLegacy($node);
                $known = $own || ($approved !== null && array_key_exists($approved, $legacy['trees']));
                $accept = match (true) {
                    $own => ReleaseNodeInputs::NodeScope.$approved === $host,
                    $known => $inputs->host($legacy['trees'][(string) $approved], $node) === $host,
                    default => $approved !== null && $legacy['unreadable'],
                };
                $check = ['host' => $host, 'accept' => $accept, 'known' => $known];
            } catch (Throwable $failure) {
                $check = $failure;
            }
            try {
                $roles = $inputs->roles($node);
            } catch (Throwable $failure) {
                $roles = $failure;
            }
            // The full Node converge for changed host inputs also applies the Gateway role, which a release converge leaves out.
            try {
                $gateway = $node->roles()->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active)->exists()
                    ? $inputs->roles($node, false, [RoleName::Gateway->value]) : [];
            } catch (Throwable $failure) {
                $gateway = $failure;
            }
            $prepared['nodes'][$node->id] = ['approved' => $approved, 'host' => $check, 'roles' => $roles, 'gateway' => $gateway];
        }

        return $prepared;
    }

    /** @param Prepared $prepared */
    private function pass(GatewayRelease $release, ?DesiredCliReleaseData $cli, bool $cliUnavailable, array $prepared): ?int
    {
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
        if (array_any($members, static fn (Node $node): bool => ! array_key_exists('host', $node->release_inputs ?? [])
            && ! array_key_exists($node->id, $prepared['baselines']))) {
            // Membership changed after the inputs were read. The next pass reads them before it takes the writer.
            return 10;
        }
        $this->recordHostBaselines($members, $prepared['baselines']);
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

        // One pass visits each Node once, from its start until the phase settles. The next pass starts every Node afresh.
        $previousRows = (array) ($phase['nodes'] ?? []);
        if (! in_array($phase['status'] ?? null, ['pending', 'deferred'], true)) {
            $phase['started_at'] = now()->toISOString();
            $previousRows = array_map(static fn (mixed $row): array => array_intersect_key(ValidatedData::object($row), array_flip(['node_id', 'name']))
                + ['status' => 'pending'], $previousRows);
        }
        // The release projection opens the first pass at verification. Drop this fallback after one release.
        $phase['started_at'] ??= $release->created_at?->copy()->addMilliseconds((int) $release->duration_ms)->toISOString() ?? now()->toISOString();
        $rows = [];
        $phase['nodes'] = &$rows;
        foreach ($members as $node) {
            $rows[$node->id] = isset($previousRows[$node->id]) ? ValidatedData::object($previousRows[$node->id])
                : ['node_id' => $node->id, 'name' => $node->name, 'status' => 'pending'];
        }
        if ($prepared['legacy'] === null) {
            $this->save($release, [...$phase, 'error_code' => 'fleet.inputs_unavailable'], 'failed');

            return null;
        }
        if (array_any($members, static fn (Node $node): bool => ! isset($prepared['nodes'][$node->id])
            || $prepared['nodes'][$node->id]['approved'] !== self::legacyApproval($node))) {
            // Membership or a host approval changed after the inputs were read. The next pass reads them again.
            return 10;
        }
        unset($phase['error_code']);
        $failed = false;
        foreach ($members as $node) {
            $row = &$rows[$node->id];
            // A Node keeps its outcome for the rest of the pass: drift after `current` waits for the next pass, and a failed
            // Node never holds back the Nodes after it.
            if (in_array($row['status'], ['current', 'failed'], true)) {
                $failed = $failed || $row['status'] === 'failed';

                continue;
            }
            try {
                $delay = $this->visit($release, $node, $row, $prepared['nodes'][$node->id], $cli, $cliUnavailable, Carbon::parse(ValidatedData::string($phase['started_at'])));
                if ($delay !== null) {
                    $this->save($release, $phase, 'pending');

                    return $delay;
                }
            } catch (ResourceOperationException $failure) {
                if (in_array($failure->errorCode, ['operation.target_busy', 'fleet.rollout_busy'], true)) {
                    $row['status'] = 'pending';
                    // The Operations a queued role converge waits for, so an operator sees what holds the Node.
                    $row['waiting_for'] = ValidatedData::integerList($failure->details['operation_ids'] ?? []);
                    $this->save($release, $phase, 'pending');

                    return 15;
                }
                $row['error_code'] = $failure->errorCode;
                $row['status'] = 'failed';
                $failed = true;
                // A failed delivery stops the pass. Any other failure, such as a changed host input, holds only this Node.
                if (($row['agent'] ?? null) === 'failed') {
                    $this->save($release, $phase, 'failed');

                    return null;
                }
            } catch (Throwable) {
                $row['error_code'] = 'fleet.inputs_unavailable';
                $row['status'] = 'failed';
                $this->save($release, $phase, 'failed');

                return null;
            }
        }
        $this->save($release, $phase, $failed ? 'failed' : 'current');

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
     * A missing previous tree records an unknown approval, so the pass converges the Node's host.
     *
     * @param  list<Node>|null  $members
     */
    public function preserveHostBaselines(?string $previousRelease, ?array $members = null): void
    {
        $members ??= $this->members();
        $this->recordHostBaselines($members, $this->hostBaselines($previousRelease, $members));
    }

    /**
     * The previous release's host digest of each member without a host approval, or null when it cannot be read.
     *
     * @param  list<Node>  $members
     * @return array<int, string|null>
     */
    private function hostBaselines(?string $previousRelease, array $members): array
    {
        $baselines = [];
        foreach ($members as $node) {
            if (array_key_exists('host', $node->release_inputs ?? [])) {
                continue;
            }
            try {
                $baselines[$node->id] = app(ReleaseNodeInputs::class)->host($previousRelease === null ? null
                    : GatewayReleaseLayout::fromConfig()->releasePath($previousRelease), $node);
            } catch (Throwable) {
                $baselines[$node->id] = null;
            }
        }

        return $baselines;
    }

    /**
     * @param  list<Node>  $members
     * @param  array<int, string|null>  $baselines
     */
    private function recordHostBaselines(array $members, array $baselines): void
    {
        $missing = array_filter($members, static fn (Node $node): bool => ! array_key_exists('host', $node->release_inputs ?? [])
            && array_key_exists($node->id, $baselines));
        if ($missing === []) {
            return;
        }

        DB::transaction(static function () use ($missing, $baselines): void {
            $locked = Node::query()->whereKey(array_map(static fn (Node $node): int => $node->id, $missing))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($missing as $member) {
                $node = $locked->get($member->id);
                if ($node !== null && ! array_key_exists('host', $node->release_inputs ?? [])) {
                    $node->forceFill(['release_inputs' => [...($node->release_inputs ?? []), 'host' => $baselines[$node->id]]])->save();
                }
                if ($node !== null) {
                    $member->release_inputs = $node->release_inputs;
                }
            }
        });
    }

    /**
     * For a Node whose host approval predates the classification by Node: the legacy digest of this tree and of each recently
     * verified release, read from Git objects, mapped to its tree. Drop after one release.
     *
     * @param  list<Node>  $members
     * @return Legacy
     */
    private function legacyTrees(GatewayRelease $release, ReleaseNodeInputs $inputs, array $members): array
    {
        $legacy = ['trees' => [], 'unreadable' => false, 'folders' => []];
        if (! array_any($members, static fn (Node $node): bool => self::legacyApproval($node) !== null)) {
            return $legacy;
        }
        $legacy['trees'][$inputs->legacyHost()] = null;
        $repository = dirname(config()->string('orbit.gateway_checkout'), 2);
        $commits = GatewayRelease::query()->where('outcome', 'verified')->where('sha', '!=', $release->sha)
            ->latest('id')->limit(20)->pluck('sha')->unique()->all();
        foreach (ValidatedData::stringList(array_values($commits)) as $commit) {
            $folder = sys_get_temp_dir().'/orbit-release-host-'.bin2hex(random_bytes(6));
            File::ensureDirectoryExists($folder, 0700);
            $legacy['folders'][] = $folder;
            try {
                $inputs->extractHostTree($repository, $commit, $folder);
                $legacy['trees'][$inputs->legacyHost($folder)] ??= $folder;
            } catch (Throwable) {
                $legacy['unreadable'] = true;
            }
        }

        return $legacy;
    }

    private static function legacyApproval(Node $node): ?string
    {
        $host = ($node->release_inputs ?? [])['host'] ?? null;

        return is_string($host) && ! str_starts_with($host, ReleaseNodeInputs::NodeScope) ? $host : null;
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  NodeInputs  $inputs
     */
    private function visit(GatewayRelease $release, Node $node, array &$row, array $inputs, ?DesiredCliReleaseData $cli, bool $cliUnavailable, Carbon $passStarted): ?int
    {
        $recorded = $node->release_inputs ?? [];
        if ($inputs['host'] instanceof Throwable) {
            throw $inputs['host'];
        }
        ['host' => $host, 'accept' => $accept, 'known' => $known] = $inputs['host'];
        if ($accept) {
            if (! $known) {
                $row['host_baseline'] = 'legacy_baseline_unreadable';
                Log::warning('A Node\'s host approval predates the per-Node classification and its release tree could not be read; '
                    .'the fleet pass records the current host inputs.', ['node_id' => $node->id, 'release_id' => $release->id]);
            }
            $locked = Node::query()->whereKey($node->id)->lockForUpdate()->firstOrFail();
            $recorded = [...($locked->release_inputs ?? []), 'host' => $host];
            $locked->forceFill(['release_inputs' => $recorded])->save();
        }
        if (! CliConvergePlan::required($node)) {
            $cli = null;
        } elseif ($cli === null) {
            // Membership changed during catalog preflight. The next visit resolves the pin before admission.
            return 10;
        }
        if ($inputs['roles'] instanceof Throwable) {
            throw $inputs['roles'];
        }
        $desired = $inputs['roles'];
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

        $row['host'] = ($recorded['host'] ?? null) === $host ? 'unchanged' : 'pending';
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
            // The full converge started for changed host inputs records the host digest when it succeeds (NodeConvergeProjection).
            $hostConverge = data_get($roleOperation->input, '_converge.intent.release_roles_only') !== true;
            if ($roleOperation->status !== OperationStatus::Succeeded) {
                foreach ($changed as $role) {
                    $row['roles'][$role['role']] = 'failed';
                }
                if ($hostConverge) {
                    $row['host'] = 'failed';
                }
                throw new ResourceOperationException($roleOperation->error_code ?? ($hostConverge ? 'fleet.host_converge_failed' : 'fleet.role_converge_failed'),
                    $hostConverge ? 'Host converge failed.' : 'Role converge failed.', 409);
            }
            if ($hostConverge && $row['host'] === 'unchanged') {
                $row['host'] = 'converged';
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
        // A role converge that succeeded during this pass, its own or one run by hand, applied the role assignment it captured.
        // Instance work on the Node moves the digest on while it waits, so the recorded digest alone never settles it.
        $converged = $this->convergedAssignments($node, $passStarted);
        foreach ($changed as $id => $role) {
            if (in_array($id, $converged, true)) {
                $row['roles'][$role['role']] = 'converged';
                unset($changed[$id]);
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
        if ($row['host'] === 'pending' || $changed !== []) {
            // Changed host inputs need the full Node converge an operator runs: the base and every role, and it records the host
            // digest. A busy refusal queues either converge ahead of new Instance work on the Node, for a bounded wait (NodeWorkAdmission).
            $full = $row['host'] === 'pending';
            if ($full && $inputs['gateway'] instanceof Throwable) {
                throw $inputs['gateway'];
            }
            $prepared = ['roles' => $full ? $desired + $inputs['gateway'] : $desired, 'host' => $host];
            ksort($prepared['roles']);
            $row['role_operation_id'] = ($full
                ? app(StartNodeConvergeAction::class)->execute($node, automatic: true, releaseInputs: $prepared, cli: $cli)
                : app(StartNodeConvergeAction::class)->execute($node, roles: array_column($changed, 'role'), release: true, releaseInputs: $prepared))->id;
            unset($row['waiting_for']);

            return 10;
        }
        $row['status'] = 'current';

        return null;
    }

    /**
     * Role assignment IDs that a succeeded, not T3-deferred `node.converge` of the Node admitted since the pass started applied.
     *
     * @return list<int>
     */
    private function convergedAssignments(Node $node, Carbon $since): array
    {
        $ids = [];
        foreach (Operation::query()->where('kind', 'node.converge')->where('node_id', $node->id)
            ->where('status', OperationStatus::Succeeded->value)->where('created_at', '>=', $since)->cursor() as $operation) {
            if (($operation->result['t3_restart_deferred'] ?? []) === []) {
                array_push($ids, ...array_map(intval(...), array_keys((array) data_get($operation->input, '_converge.release_inputs.roles', []))));
            }
        }

        return array_values(array_unique($ids));
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
