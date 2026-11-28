<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Actions\Nodes\StartNodeConvergeAction;
use App\Data\Fleet\DesiredCliReleaseData;
use App\Domain\ConnServer\ConnServerRollout;
use App\Domain\Fleet\CliConvergePlan;
use App\Domain\Fleet\CliReleaseUnavailableReason;
use App\Domain\Fleet\DesiredFleetState;
use App\Domain\Fleet\FleetReboot;
use App\Domain\Fleet\FleetReleaseLag;
use App\Domain\Fleet\FleetRolloutAlerts;
use App\Domain\Fleet\FleetRolloutMembership;
use App\Domain\Fleet\FleetServingRelease;
use App\Domain\Fleet\ReleaseNodeInputs;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseFollowUp;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayVersion;
use App\Domain\Instances\NestedFleetRefresh;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Proof\ProofSeeds;
use App\Domain\Releases\ReleaseAlert;
use App\Domain\Releases\ReleaseAlertKind;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Domain\Releases\ReleaseAlertSubject;
use App\Domain\Releases\ReleaseIncident;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\AgentReportedVersions;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\MachineSoftware\ConnServerRelease;
use App\Models\GatewayRelease;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Container\Attributes\Tag;
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
 * @phpstan-type Prepared array{baselines: array<int, string|null>, legacy: Legacy|null, nodes: array<int, NodeInputs>, conn_server_pin: ConnServerRelease|ResourceOperationException|null}
 */
final readonly class ContinueReleaseFleetAction
{
    /** The fleet phase's outcomes after which the follow-up phases run. */
    private const array Settled = ['current', 'disabled', 'failed'];

    /** @param iterable<int, GatewayReleaseFollowUp> $followUps */
    public function __construct(#[Tag(GatewayReleaseFollowUp::class)] private iterable $followUps) {}

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
                || ($refresh === 'current' && in_array($seed, [null, 'pending'], true))))
            || (in_array($fleet, self::Settled, true) && $this->followUpPending($release))) {
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
        $fleet = data_get($current?->phases, 'fleet.status');
        if ($current?->id !== $release->id || ! in_array($fleet, self::Settled, true)) {
            return null;
        }
        $before = $current->phases;
        $followUps = $this->advanceFollowUps($current);
        if ($fleet === 'failed') {
            // A failed Node holds back the default refresh, never the follow-up phases.
            $delay = DB::transaction(static function () use ($current, $followUps): ?int {
                $current->forceFill(['phases' => [...$current->phases, ...$followUps]])->save();

                return self::waiting($followUps) ? 60 : null;
            });
        } else {
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

            $delay = DB::transaction(function () use ($current, $refresh, $followUps): ?int {
                // The database writer orders publication and its receipt with the default's seed projection.
                $phases = [...$current->phases, 'default_refresh' => $refresh, ...$followUps];
                if ($refresh['status'] === 'current' && data_get($phases, 'seed_publication.status') !== 'current') {
                    $phases['seed_publication'] = app(ProofSeeds::class)->releasePublication((string) $current->sha, $refresh['defaults']);
                }
                if ($refresh['status'] === 'disabled' && data_get($phases, 'seed_publication.status') === 'pending') {
                    $publication = ValidatedData::object($phases['seed_publication']);
                    $phases['seed_publication'] = [...$publication, 'status' => 'disabled'];
                }
                $current->forceFill(['phases' => $phases])->save();

                return $refresh['status'] === 'pending' || data_get($phases, 'seed_publication.status') === 'pending' || self::waiting($followUps) ? 60 : null;
            });
        }
        foreach ($followUps as $phase => $record) {
            // Once per phase, commit and error code: a pass that finds the same failure again raises nothing.
            if ($record['status'] === 'failed' && (data_get($before, $phase.'.status') !== 'failed'
                || data_get($before, $phase.'.error_code') !== ($record['error_code'] ?? null))) {
                $this->alertFollowUp($current, $phase, $record);
            }
        }

        return $delay;
    }

    /** @param array<string, array<string, mixed>> $followUps */
    private static function waiting(array $followUps): bool
    {
        return in_array('pending', array_column($followUps, 'status'), true);
    }

    /**
     * Advances each follow-up phase by one step, outside the SQLite writer. A follow-up that throws fails its own phase only.
     *
     * @return array<string, array<string, mixed>>
     */
    private function advanceFollowUps(GatewayRelease $release): array
    {
        $records = [];
        foreach ($this->followUps as $followUp) {
            $phase = $followUp->phase();
            try {
                $record = $followUp->advance($release, ValidatedData::object($release->phases[$phase] ?? []));
                $records[$phase] = [...$record, 'status' => ValidatedData::string($record['status'])];
            } catch (Throwable $failure) {
                report($failure);
                $records[$phase] = ['status' => 'failed', 'error_code' => 'release.follow_up_unavailable'];
            }
        }

        return $records;
    }

    /** Whether a follow-up phase waits for its work. The pass that settles the fleet phase records each follow-up phase. */
    private function followUpPending(GatewayRelease $release): bool
    {
        foreach ($this->followUps as $followUp) {
            if (data_get($release->phases, $followUp->phase().'.status') === 'pending') {
                return true;
            }
        }

        return false;
    }

    /**
     * Raises `release_follow_up_failed` once per phase, commit and error code. It runs after the record commits; it never fails the pass.
     *
     * @param  array<string, mixed>  $record
     */
    private function alertFollowUp(GatewayRelease $release, string $phase, array $record): void
    {
        $code = ValidatedData::nullableString($record['error_code'] ?? null) ?? 'release.follow_up_failed';
        $operation = isset($record['operation_id']) ? ' Operation '.ValidatedData::integer($record['operation_id']).'.' : '';
        try {
            app(ReleaseAlertNotifier::class)->alert(new ReleaseAlert(
                ReleaseAlertKind::ReleaseFollowUpFailed,
                new ReleaseAlertSubject('gateway', FleetRolloutAlerts::repository(), (string) $release->sha, 'release-'.$release->id),
                "The {$phase} phase of release ".GatewayReleaseCommit::id((string) $release->sha)." failed: {$code}.{$operation}",
                new ReleaseIncident('release-phase:'.$phase, (string) $release->sha, $code),
            ));
        } catch (Throwable $failure) {
            report($failure);
        }
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
        $prepared = ['baselines' => $this->hostBaselines($release->previous_release_id, $members), 'legacy' => null, 'nodes' => [], 'conn_server_pin' => null];
        if (! config('fleet.rollout')) {
            return $prepared;
        }
        try {
            $prepared['conn_server_pin'] = app(ConnServerRollout::class)->releasePin();
        } catch (ResourceOperationException $failure) {
            $prepared['conn_server_pin'] = $failure;

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
        // Pending downstream work continues before the next pass starts the fleet afresh.
        if ((($phase['status'] ?? null) === 'current' && (data_get($release->phases, 'default_refresh.status') === 'pending'
            || data_get($release->phases, 'seed_publication.status') === 'pending'))
            || (in_array($phase['status'] ?? null, ['current', 'failed'], true) && $this->followUpPending($release))) {
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

        $pin = $prepared['conn_server_pin'];
        if ($pin instanceof ResourceOperationException) {
            $this->save($release, [...$phase, 'error_code' => $pin->errorCode], 'failed');

            return null;
        }
        try {
            $canary = app(ConnServerRollout::class)->releaseGate($pin);
        } catch (ResourceOperationException $failure) {
            if ($failure->errorCode === 'operation.target_busy') {
                return 15;
            }
            $this->save($release, [...$phase, 'error_code' => $failure->errorCode], 'failed');

            return null;
        }
        if ($canary !== null) {
            $phase['conn_server_operation_id'] = $canary->id;
            if ($canary->status->terminal()) {
                $this->save($release, [...$phase, 'error_code' => $canary->error_code ?? 'conn_server.canary_failed'], 'failed');

                return null;
            }
            $this->save($release, $phase, 'pending');

            return 15;
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
            // The Operations that held the Node at the last refusal; a pass that gets past the refusal drops them, so an ended wait
            // never stays listed.
            unset($row['waiting_for']);
            try {
                $delay = $this->visit($release, $node, $row, $prepared['nodes'][$node->id], $cli, $cliUnavailable);
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
    private function visit(GatewayRelease $release, Node $node, array &$row, array $inputs, ?DesiredCliReleaseData $cli, bool $cliUnavailable): ?int
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
        // Only a converge that started after the Node's last delivery counts: a later delivery may change what it applied.
        $roleOperation = $this->operation($row, 'role_operation_id');
        if ($roleOperation !== null && $deliveryOperation !== null && $roleOperation->id < $deliveryOperation->id) {
            $roleOperation = null;
        }
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
        // Each Node with a managed agent converges once per pass, after delivery: the same full Node converge an operator runs. Its
        // own phase digests decide which phases run, and a succeeded one records the host and role digests (NodeConvergeProjection).
        // A Node without one converges only for changed release inputs, which its admission refuses.
        $settled = ! app(ManagedNodeEligibility::class)->convergeable($node) && $row['host'] !== 'pending' && $changed === [];
        if ($roleOperation !== null) {
            if ($roleOperation->status !== OperationStatus::Succeeded) {
                foreach ($changed as $role) {
                    $row['roles'][$role['role']] = 'failed';
                }
                if ($row['host'] === 'pending') {
                    $row['host'] = 'failed';
                }
                throw new ResourceOperationException($roleOperation->error_code ?? 'fleet.node_converge_failed', 'Node converge failed.', 409);
            }
            if ($row['host'] === 'unchanged') {
                $row['host'] = 'converged';
            }
            if (($roleOperation->result['t3_restart_deferred'] ?? []) !== []) {
                $row['status'] = 'pending';
                if ($roleOperation->finished_at?->gt(now()->subSeconds(FleetReleaseLag::RetryAfterSeconds))) {
                    return FleetReleaseLag::RetryAfterSeconds;
                }
            } else {
                $settled = true;
                $capture = ValidatedData::object($roleOperation->input['_converge']);
                $intent = ValidatedData::object($capture['intent']);
                foreach (ValidatedData::stringList($intent['selected_roles']) as $role) {
                    $row['roles'][$role] = 'converged';
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
        if (! $settled) {
            // A busy refusal queues the converge ahead of new Instance work on the Node, for a bounded wait (NodeWorkAdmission).
            if ($inputs['gateway'] instanceof Throwable) {
                throw $inputs['gateway'];
            }
            $prepared = ['roles' => $desired + $inputs['gateway'], 'host' => $host];
            ksort($prepared['roles']);
            $row['role_operation_id'] = app(StartNodeConvergeAction::class)->execute($node, automatic: true, releaseInputs: $prepared, cli: $cli)->id;

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
