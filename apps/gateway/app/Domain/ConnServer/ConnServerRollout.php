<?php

declare(strict_types=1);

namespace App\Domain\ConnServer;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Fleet\FleetServingRelease;
use App\Domain\Nodes\SwampNode;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ActiveToolPolicy;
use App\Domain\Tools\ToolConvergeIntent;
use App\Domain\Tools\ToolDefinitionResolver;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolPolicy;
use App\Infrastructure\MachineSoftware\ConnServerRelease;
use App\Infrastructure\MachineSoftware\ConnServerReleases;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\ConnServerNode;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Tool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class ConnServerRollout
{
    public const string Kind = 'fleet.conn_server_rollout';

    public const array Scopes = ['canary', 'fleet', 'nodes'];

    private const string Lock = self::Kind;

    public function __construct(
        private StartOperationAction $start,
        private ConnServerReleases $releases,
        private SwampSshExecutionInput $ssh,
        private OperationIdempotency $idempotency,
        private FleetServingRelease $serving,
        private ToolConvergeIntent $tools,
        private ToolPolicy $policy,
        private ToolDefinitionResolver $definitions,
        private ConnServerToolManager $manager,
    ) {}

    private function receipt(string $pin, string $stage): ?Operation
    {
        return Operation::query()->where('kind', self::Kind)->where('input->release_pin', $pin)
            ->where('input->release_stage', $stage)->latest('id')->first();
    }

    private function pinIdentity(ConnServerRelease $pin): string
    {
        return hash('sha256', json_encode([$pin->version, $pin->linux, $pin->mac], JSON_THROW_ON_ERROR));
    }

    private function fingerprint(ConnServerRelease $pin, ConnServerNode $canary): string
    {
        return hash('sha256', json_encode([$this->pinIdentity($pin), $this->nodeIntent($canary)], JSON_THROW_ON_ERROR));
    }

    private function passed(?Operation $receipt, ConnServerRelease $pin, ConnServerNode $canary, bool $dryRun): bool
    {
        return $receipt?->status === OperationStatus::Succeeded
            && data_get($receipt->result, 'version') === $pin->version
            && data_get($receipt->result, 'dry_run') === $dryRun
            && data_get($receipt->result, 'nodes.0.node_id') === $canary->node_id
            && data_get($receipt->result, 'nodes.0.outcome') === ($dryRun ? 'checked' : 'updated')
            && ($dryRun || (data_get($receipt->result, 'nodes.0.thread_read') === true && $this->noObservedVersionDrift($canary, $pin->version)));
    }

    public function releasePin(?string $version = null): ConnServerRelease
    {
        try {
            return $this->releases->find($version)
                ?? throw new ResourceOperationException('conn_server.pin_unavailable', 'The requested Conn Server host release is not in the release catalog.', 409);
        } catch (InvalidArgumentException $failure) {
            throw $this->catalogUnavailable($failure);
        }
    }

    /** The release pass holds the release lock and database writer. It resumes from durable Operations. */
    public function releaseGate(?ConnServerRelease $pin): ?Operation
    {
        if (! $pin?->releaseCanary) {
            return null;
        }
        $configured = $this->servers();
        $servers = $this->reviewedTargets($configured, $this->policy->active(), $pin);
        if ($servers->isNotEmpty()) {
            $this->requireCanary($configured);
        }
        $canary = $servers->firstWhere('canary', true);
        if ($canary === null) {
            return null;
        }
        $key = $this->fingerprint($pin, $canary);
        $onlyCanary = $servers->take(1);
        $check = $this->receipt($key, 'check') ?? $this->releaseOperation($pin, $key, 'check', $onlyCanary, 'gateway-release');
        if (! $this->passed($check, $pin, $canary, true)) {
            return $check;
        }
        $probe = $this->receipt($key, 'canary') ?? $this->releaseOperation($pin, $key, 'canary', $onlyCanary, 'gateway-release');
        if (! $this->passed($probe, $pin, $canary, false)) {
            return $probe;
        }
        $rest = $servers->skip(1);
        if ($rest->isNotEmpty() && $this->receipt($key, 'preflight') === null) {
            $this->preflight($pin, $key, $rest, 'gateway-release');
        }

        return null;
    }

    /** @param list<int>|null $nodeIds the Nodes of scope `nodes`, in visiting order */
    public function admit(bool $dryRun, string $scope, ?array $nodeIds = null, ?string $triggeredBy = null, ?string $requestId = null, ?string $version = null, bool $withdraw = false, ?int $canaryOperationId = null, ?int $retryOperationId = null): Operation
    {
        return DB::transaction(fn (): Operation => $this->idempotency->replay(self::Kind) ?? $this->idempotency->bind(
            $this->admitCurrent($dryRun, $scope, $nodeIds, $triggeredBy, $requestId, $version, $withdraw, $canaryOperationId, $retryOperationId),
        ));
    }

    /** @param list<int>|null $nodeIds */
    private function admitCurrent(bool $dryRun, string $scope, ?array $nodeIds, ?string $triggeredBy, ?string $requestId, ?string $version, bool $withdraw, ?int $canaryOperationId, ?int $retryOperationId): Operation
    {
        $servers = $this->servers();
        if ($withdraw) {
            if ($scope !== 'nodes' || count($nodeIds ?? []) !== 1 || $version !== null || $canaryOperationId !== null || $retryOperationId !== null) {
                throw new ResourceOperationException('conn_server.withdraw_scope_invalid', 'Withdrawal requires one explicit Node and no release override or promotion receipt.', 422);
            }
            $target = $servers->firstWhere('node_id', $nodeIds[0]);
            if ($target === null || ! $target->tool_withdrawn || $target->platform !== 'linux' || ! $this->manager->supportsNode($target->node)) {
                throw new ResourceOperationException('conn_server.withdraw_unsupported', 'Service withdrawal requires a configured Linux x64 Node with a withdrawn Tool membership.', 409);
            }

            return $this->run(null, collect([$target]), $dryRun, false, $triggeredBy, $requestId);
        }
        if ($dryRun && $canaryOperationId !== null) {
            throw new ResourceOperationException('conn_server.promotion_input_invalid', 'The canary receipt is only used to promote a release, not in a dry run.', 422);
        }
        $eligible = $servers->where('tool_withdrawn', false);
        if ($scope !== 'nodes') {
            $this->requireCanary($eligible);
        }
        $selected = match ($scope) {
            'canary' => $eligible->take(1),
            'nodes' => collect($nodeIds)->map(fn (int $id): ConnServerNode => $eligible->firstWhere('node_id', $id)
                ?? throw new ResourceOperationException('conn_server.membership_withdrawn', 'A selected Node is not enrolled for Conn Server rollout.', 409)),
            default => $eligible,
        };
        $active = $this->policy->active();
        $anchor = $selected->firstOrFail();
        $desired = $this->desired($active, $anchor->managedTool, $version);
        $pin = $this->releasePin($desired);
        if ($version !== null && $version !== $pin->version) {
            $this->releaseConflict();
        }
        if ($scope !== 'fleet' || ! $pin->releaseCanary) {
            foreach ($selected as $server) {
                if ($this->desired($active, $server->managedTool, $version) !== $pin->version) {
                    $this->releaseConflict();
                }
            }
        }
        if (! $pin->releaseCanary && ($canaryOperationId !== null || $retryOperationId !== null)) {
            throw new ResourceOperationException('conn_server.pin_changed', 'The serving pin has no managed canary receipt.', 409);
        }
        if (! $pin->releaseCanary || ($dryRun && $scope === 'nodes')) {
            return $this->run($pin, $selected, $dryRun, false, $triggeredBy, $requestId);
        }
        if ($this->serving->supersedes() || $this->serving->read()['state'] === FleetServingRelease::Unreadable) {
            throw new ResourceOperationException('conn_server.pin_changed', 'The serving Gateway release changed. Read its current canary receipt.', 409);
        }

        if ($scope === 'nodes') {
            throw new ResourceOperationException('conn_server.canary_required', 'Start the reviewed release on its enrolled canary, then promote it with scope fleet.', 409);
        }
        $reviewed = $this->reviewedTargets($eligible, $active, $pin);
        $canary = $reviewed->firstWhere('canary', true);
        if ($canary === null) {
            throw new ResourceOperationException('conn_server.canary_required', 'Enroll the designated canary at the reviewed release before running it or promoting it.', 409);
        }
        $rest = $reviewed->skip(1);

        $key = $this->fingerprint($pin, $canary);
        $check = $this->receipt($key, 'check');
        if ($scope === 'canary' && $dryRun) {
            return $this->retry($pin, $key, 'check', $selected, $check, $triggeredBy, $requestId, $retryOperationId);
        }
        if ($dryRun) {
            if ($rest->isEmpty()) {
                return $this->run($pin, $reviewed, true, false, $triggeredBy, $requestId);
            }
            $preflight = $this->receipt($key, 'preflight');

            return $this->retry($pin, $key, 'preflight', $rest, $preflight, $triggeredBy, $requestId, $retryOperationId);
        }
        $probe = $this->receipt($key, 'canary');
        if ($scope === 'canary') {
            if ($probe !== null && $this->passed($probe, $pin, $canary, false)) {
                return $probe;
            }
            $dryPassed = $this->passed($check, $pin, $canary, true);

            return $this->retry($pin, $key, $dryPassed ? 'canary' : 'check', $selected,
                $dryPassed ? $probe : $check, $triggeredBy, $requestId, $retryOperationId);
        }

        if ($scope !== 'fleet' || $canaryOperationId === null || $probe?->id !== $canaryOperationId
            || ! $this->passed($probe, $pin, $canary, false)) {
            throw new ResourceOperationException('conn_server.canary_required', 'Promote the serving pin with scope fleet and its successful canary_operation_id.', 409);
        }
        $pinIdentity = $this->pinIdentity($pin);
        $promotion = $this->receipt($pinIdentity, 'promotion');
        $pending = $rest;
        if ($promotion !== null) {
            if (! $promotion->status->terminal() || ($promotion->status !== OperationStatus::Succeeded && $retryOperationId === null)) {
                return $promotion;
            }
            if ($promotion->status === OperationStatus::Succeeded) {
                $pending = $this->remainingPromotionNodes($pin, $pinIdentity, $rest);
                if ($pending->isEmpty() || $retryOperationId !== null) {
                    return $promotion;
                }
            }
        }
        if ($rest->isEmpty()) {
            throw new ResourceOperationException('conn_server.no_promotion_targets', 'There are no remaining standalone Conn Server Nodes.', 409);
        }
        $preflight = $this->receipt($key, 'preflight');
        if (data_get($preflight?->result, 'error_code') === 'conn_server.preflight_blocked') {
            throw new ResourceOperationException('conn_server.preflight_required', 'Review the held Nodes in the fleet dry run.', 409);
        }
        $targets = $rest->map($this->nodeIntent(...))->values()->all();
        $results = (array) ($preflight?->result['nodes'] ?? []);
        if ($preflight?->status !== OperationStatus::Succeeded || data_get($preflight->result, 'version') !== $pin->version
            || data_get($preflight->input, 'intent.nodes') !== $targets
            || data_get($preflight->result, 'dry_run') !== true
            || array_column($results, 'node_id') !== array_column($targets, 'node_id')
            || array_unique(array_column($results, 'outcome')) !== ['checked']) {
            throw new ResourceOperationException('conn_server.preflight_required', 'Repeat the fleet dry run until every remaining Node reports checked.', 409);
        }

        if ($retryOperationId !== null) {
            $retry = Operation::query()->where('kind', self::Kind)->where('input->release_pin', $pinIdentity)
                ->where('input->release_stage', 'promotion')->where('input->retry_operation_id', $retryOperationId)->first();
            if ($retry !== null) {
                return $retry;
            }
            if ($promotion?->id !== $retryOperationId) {
                throw new ResourceOperationException('conn_server.retry_stale', 'Retry the latest failed promotion for this pin.', 409);
            }
            $pending = $this->remainingPromotionNodes($pin, $pinIdentity, $rest);
        }

        return $this->releaseOperation($pin, $pinIdentity, 'promotion', $pending, $triggeredBy, $requestId,
            retry: $retryOperationId, proof: $probe->id, after: $promotion?->status === OperationStatus::Succeeded ? $promotion->id : null);
    }

    /**
     * @param  Collection<int, ConnServerNode>  $servers
     * @return Collection<int, ConnServerNode>
     */
    private function remainingPromotionNodes(ConnServerRelease $pin, string $key, Collection $servers): Collection
    {
        $completed = [];
        foreach (Operation::query()->where('kind', self::Kind)->where('input->release_pin', $key)
            ->where('input->release_stage', 'promotion')->get() as $attempt) {
            $results = (array) ($attempt->result['nodes'] ?? []);
            $targets = (array) data_get($attempt->input, 'intent.nodes', []);
            if (array_column($results, 'node_id') !== array_column($targets, 'node_id')) {
                throw new ResourceOperationException('conn_server.promotion_outcome_unknown', 'Confirm which Nodes changed before another promotion can run.', 409);
            }
            foreach ($results as $index => $result) {
                if (! is_array($result)) {
                    throw new ResourceOperationException('conn_server.promotion_outcome_unknown', 'Confirm which Nodes changed before another promotion can run.', 409);
                }
                if (($result['outcome'] ?? null) === 'updated') {
                    $completed[] = $this->installationIdentity($targets[$index]);
                }
                if (($result['outcome'] ?? null) === 'failed' && ($result['rolled_back'] ?? false) === false
                    && ($result['changed'] ?? true) === true) {
                    throw new ResourceOperationException('conn_server.promotion_outcome_unknown', 'Confirm the failed Node recovered before retrying.', 409);
                }
            }
        }

        return $servers->reject(fn (ConnServerNode $server): bool => $this->noObservedVersionDrift($server, $pin->version)
            && in_array($this->installationIdentity($this->nodeIntent($server)), $completed, true));
    }

    private function noObservedVersionDrift(ConnServerNode $server, string $version): bool
    {
        $observed = $server->managedTool?->installed_version;

        return $observed === null || $observed === $version;
    }

    /** @return array{mixed, mixed, mixed, mixed, mixed} */
    private function installationIdentity(mixed $target): array
    {
        return [data_get($target, 'node_id'), data_get($target, 'tool_id'), data_get($target, 'server.platform'),
            data_get($target, 'server.service'), data_get($target, 'server.path')];
    }

    /** Stage keys use a separator that the HTTP Idempotency-Key cannot contain. */
    private function stageKey(string $pin, string $stage, ?int $retry): string
    {
        return "conn-release/{$pin}:{$stage}:".($retry ?? 'first');
    }

    /** @param Collection<int, ConnServerNode> $servers */
    private function preflight(ConnServerRelease $pin, string $key, Collection $servers, ?string $by, ?string $requestId = null, ?int $retry = null): Operation
    {
        $blockers = [];
        foreach ($servers as $server) {
            try {
                $this->nodeIntent($server);
            } catch (ResourceOperationException $problem) {
                $blockers[$server->node_id] = $problem->errorCode;
            }
        }
        if ($blockers === []) {
            return $this->releaseOperation($pin, $key, 'preflight', $servers, $by, $requestId, $retry);
        }
        $this->assertNoActiveRollout();
        $identity = $this->stageKey($key, 'preflight', $retry);
        $nodes = $servers->map(static fn (ConnServerNode $server): array => isset($blockers[$server->node_id])
            ? ['node_id' => $server->node_id, 'outcome' => 'held', 'reason' => $blockers[$server->node_id]]
            : ['node_id' => $server->node_id, 'outcome' => 'not_reached'])->values()->all();

        return $this->idempotency->under(self::Kind, $identity, $identity, fn (): Operation => $this->start->record(
            self::Kind, 'fleet', SwampNode::id(), SwampNode::id(), ['release_pin' => $key, 'release_stage' => 'preflight',
                ...($retry === null ? [] : ['retry_operation_id' => $retry])],
            ['version' => $pin->version, 'dry_run' => true, 'nodes' => $nodes, 'error_code' => 'conn_server.preflight_blocked'],
            $by, $requestId, lockKey: self::Lock,
        ));
    }

    private function desired(ActiveToolPolicy $active, ?Tool $member, ?string $version = null): ?string
    {
        $legacyOverride = $active->mode === 'database' && $member === null ? $version : null;

        try {
            return $this->definitions->resolve('update', $this->manager, ConnServerToolManager::Package, $member, $legacyOverride, null, null, $active)->version;
        } catch (InvalidArgumentException $failure) {
            throw $this->catalogUnavailable($failure);
        }
    }

    private function catalogUnavailable(InvalidArgumentException $failure): ResourceOperationException
    {
        return new ResourceOperationException('conn_server.release_catalog_unavailable', 'The serving Conn Server host release catalog is missing, unreadable, or invalid.', 409, $failure);
    }

    private function releaseConflict(): never
    {
        throw new ResourceOperationException('conn_server.release_conflict', 'Selected Nodes must desire the requested release. Use an explicit Node scope, or update the Tool memberships.', 409);
    }

    /**
     * @param Collection<int, ConnServerNode> $servers
     * @return Collection<int, ConnServerNode>
     */
    private function reviewedTargets(Collection $servers, ActiveToolPolicy $active, ConnServerRelease $pin): Collection
    {
        $fileVersion = $active->mode === 'file' ? $active->definition(ToolManagerName::ConnServer->value, ConnServerToolManager::Package)?->version : null;

        return $servers->filter(fn (ConnServerNode $server): bool => ! $server->tool_withdrawn && $server->managedTool !== null
            && ($active->mode === 'file' ? $fileVersion : $server->managedTool->desired_version) === $pin->version
            && $this->desired($active, $server->managedTool) === $pin->version);
    }

    /** @return Collection<int, ConnServerNode> */
    private function servers(): Collection
    {
        $ids = ConnServerNode::query()->pluck('node_id');
        Node::query()->whereIn('id', [...$ids, SwampNode::id()])->orderBy('id')->lockForUpdate()->get(['id']);
        return ConnServerNode::query()->with('node', 'managedTool.aptRepository')->whereIn('node_id', $ids)->orderByDesc('canary')->orderBy('node_id')->get();
    }

    /** @param Collection<int, ConnServerNode> $servers */
    private function requireCanary(Collection $servers): void
    {
        if ($servers->where('canary', true)->count() !== 1) {
            throw new ResourceOperationException('conn_server.canary_unset', 'Exactly one Conn Server Node must be the canary.', 409);
        }
    }

    /** @return array<string, mixed> */
    private function nodeIntent(ConnServerNode $server): array
    {
        return ['node_id' => $server->node_id, 'ssh' => $this->ssh->forNode($server->node),
            ...($server->managedTool === null ? [] : ['tool_id' => $server->managedTool->id]),
            'server' => ['platform' => $server->platform, 'path' => $server->path, 'service' => $server->service, 'port' => $server->port,
                'user' => $server->user, 'canary' => $server->canary, 'notify_before_restart' => $server->notify_before_restart]];
    }

    /** @param Collection<int, ConnServerNode> $servers */
    private function retry(ConnServerRelease $pin, string $key, string $stage, Collection $servers, ?Operation $previous, ?string $by, ?string $requestId, ?int $retryId): Operation
    {
        if ($retryId === null && $previous !== null) {
            return $previous;
        }
        if ($retryId !== null && ($previous?->id !== $retryId || ! $previous->status->terminal())) {
            $existing = Operation::query()->where('kind', self::Kind)->where('input->release_pin', $key)
                ->where('input->release_stage', $stage)->where('input->retry_operation_id', $retryId)->first();
            if ($existing !== null) {
                return $existing;
            }

            throw new ResourceOperationException('conn_server.retry_stale', 'Retry the latest finished check for this pin.', 409);
        }

        return $stage === 'preflight'
            ? $this->preflight($pin, $key, $servers, $by, $requestId, $retryId)
            : $this->releaseOperation($pin, $key, $stage, $servers, $by, $requestId, $retryId);
    }

    /** @param Collection<int, ConnServerNode> $servers */
    private function releaseOperation(ConnServerRelease $pin, string $key, string $stage, Collection $servers, ?string $by, ?string $requestId = null, ?int $retry = null, ?int $proof = null, ?int $after = null): Operation
    {
        // Failed attempts remain as evidence. An explicit retry is keyed by the exact failed attempt it replaces.
        $identity = $this->stageKey($key, $stage, $retry).($after === null ? '' : ":after-{$after}");

        $receipt = ['release_pin' => $key, 'release_stage' => $stage,
            ...($retry === null ? [] : ['retry_operation_id' => $retry]),
            ...($proof === null ? [] : ['canary_operation_id' => $proof])];

        return $this->idempotency->under(self::Kind, $identity, $identity, function () use ($pin, $stage, $servers, $by, $requestId, $receipt): Operation {
            if ($stage === 'promotion' && $servers->isEmpty()) {
                return $this->start->record(self::Kind, 'fleet', SwampNode::id(), SwampNode::id(),
                    [...$receipt, 'intent' => ['nodes' => []]],
                    ['version' => $pin->version, 'dry_run' => false, 'nodes' => [], 'error_code' => null],
                    $by, $requestId, lockKey: self::Lock);
            }

            return $this->run($pin, $servers, in_array($stage, ['check', 'preflight'], true), $stage === 'canary', $by, $requestId, $receipt);
        });
    }

    /**
     * @param  Collection<int, ConnServerNode>  $servers
     * @param  array<string, int|string>  $release
     */
    private function run(?ConnServerRelease $pin, Collection $servers, bool $dryRun, bool $probe, ?string $by, ?string $requestId, array $release = []): Operation
    {
        $this->assertNoActiveRollout();
        foreach ($servers as $server) {
            $this->tools->assertIdle($server->node_id, [ToolManagerName::ConnServer->value]);
        }
        $notice = Config::array('orbit.conn.server_rollout_notice');

        return $this->start->execute(self::Kind, 'fleet', SwampNode::id(), SwampNode::id(), [...$release, 'intent' => [
            'probe_thread' => $probe,
            'stop_on_hold' => ($release['release_stage'] ?? null) === 'promotion',
            'dry_run' => $dryRun,
            ...($pin === null ? ['withdraw' => true] : [
                'version' => $pin->version,
                'mac' => ['url' => $pin->mac->artifact->location, 'sha256' => $pin->mac->artifact->digest, 'bundle_id' => $pin->mac->bundleId, 'team_id' => $pin->mac->teamId],
                'linux' => ['url' => $pin->linux->location, 'sha256' => $pin->linux->digest],
            ]),
            'notice' => ['url' => $notice['url'] ?? null, 'command' => $notice['command'] ?? null],
            'nodes' => $servers->map($this->nodeIntent(...))->values()->all(),
        ]], $by, $requestId, lockKey: self::Lock);
    }

    private function assertNoActiveRollout(): void
    {
        if (ToolConvergeIntent::writers()->where('kind', self::Kind)->exists()) {
            throw new ResourceOperationException('operation.target_busy', 'Another Conn Server rollout holds the rollout lock.', 409);
        }
    }
}
