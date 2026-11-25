<?php

declare(strict_types=1);

namespace App\Domain\ConnServer;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Fleet\FleetServingRelease;
use App\Domain\Nodes\SwampNode;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\MachineSoftware\CurrentRecipe;
use App\Infrastructure\MachineSoftware\FolderPin;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\ConnServerNode;
use App\Models\Operation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

final readonly class ConnServerRollout
{
    public const string Kind = 'fleet.conn_server_rollout';

    public const array Scopes = ['canary', 'fleet', 'nodes'];

    private const string Lock = 'fleet:conn-server-rollout';

    public function __construct(
        private StartOperationAction $start,
        private CurrentRecipe $recipes,
        private SwampSshExecutionInput $ssh,
        private OperationIdempotency $idempotency,
        private FleetServingRelease $serving,
    ) {}

    private function receipt(string $pin, string $stage): ?Operation
    {
        return Operation::query()->where('kind', self::Kind)->where('input->release_pin', $pin)
            ->where('input->release_stage', $stage)->latest('id')->first();
    }

    private function pinIdentity(FolderPin $pin): string
    {
        return hash('sha256', json_encode([$pin->version, $pin->artifact, $pin->macApp, $pin->bin], JSON_THROW_ON_ERROR));
    }

    private function fingerprint(FolderPin $pin, ConnServerNode $canary): string
    {
        return hash('sha256', json_encode([$this->pinIdentity($pin), $this->nodeIntent($canary)], JSON_THROW_ON_ERROR));
    }

    private function passed(?Operation $receipt, FolderPin $pin, ConnServerNode $canary, bool $dryRun): bool
    {
        return $receipt?->status === OperationStatus::Succeeded
            && data_get($receipt->result, 'version') === $pin->version
            && data_get($receipt->result, 'dry_run') === $dryRun
            && data_get($receipt->result, 'nodes.0.node_id') === $canary->node_id
            && data_get($receipt->result, 'nodes.0.outcome') === ($dryRun ? 'checked' : 'updated')
            && ($dryRun || data_get($receipt->result, 'nodes.0.thread_read') === true);
    }

    /** The release pass holds the release lock and database writer. It resumes from durable Operations. */
    public function releaseGate(): ?Operation
    {
        $pin = $this->recipes->currentRecipe()->instances?->agent['conn-server'] ?? null;
        if (! $pin?->releaseCanary) {
            return null;
        }
        $servers = $this->servers();
        $canary = $servers->firstOrFail();
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
    public function admit(bool $dryRun, string $scope, ?array $nodeIds = null, ?string $triggeredBy = null, ?string $requestId = null, ?int $canaryOperationId = null, ?int $retryOperationId = null): Operation
    {
        return DB::transaction(function () use ($dryRun, $scope, $nodeIds, $triggeredBy, $requestId, $canaryOperationId, $retryOperationId): Operation {
            if (($replay = $this->idempotency->replay(self::Kind)) !== null) {
                return $replay;
            }
            $pin = $this->pin();
            $servers = $this->servers();
            $canary = $servers->firstOrFail();
            $selected = match ($scope) {
                'canary' => $servers->take(1),
                'nodes' => $servers->sortBy(static fn (ConnServerNode $server): int|false => array_search($server->node_id, $nodeIds ?? [], true))
                    ->whereIn('node_id', $nodeIds ?? []),
                default => $servers,
            };
            if (! $pin->releaseCanary && ($canaryOperationId !== null || $retryOperationId !== null)) {
                throw new ResourceOperationException('conn_server.pin_changed', 'The serving pin has no managed canary receipt.', 409);
            }
            if (! $pin->releaseCanary || ($dryRun && $scope !== 'fleet')) {
                return $this->run($pin, $selected, $dryRun, false, $triggeredBy, $requestId);
            }
            if ($this->serving->supersedes() || $this->serving->read()['state'] === FleetServingRelease::Unreadable) {
                throw new ResourceOperationException('conn_server.pin_changed', 'The serving Gateway release changed. Read its current canary receipt.', 409);
            }

            $key = $this->fingerprint($pin, $canary);
            if ($dryRun) {
                $rest = $servers->skip(1);
                if ($rest->isEmpty()) {
                    return $this->run($pin, $selected, true, false, $triggeredBy, $requestId);
                }
                $preflight = $this->receipt($key, 'preflight');

                return $this->retry($pin, $key, 'preflight', $rest, $preflight, $triggeredBy, $requestId, $retryOperationId);
            }
            $check = $this->receipt($key, 'check');
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
            if ($promotion !== null && ($retryOperationId === null || ! $promotion->status->terminal() || $promotion->status === OperationStatus::Succeeded)) {
                return $promotion;
            }
            $rest = $servers->skip(1);
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
                $rest = $this->remainingPromotionNodes($pinIdentity, $rest);
            }

            return $this->releaseOperation($pin, $pinIdentity, 'promotion', $rest, $triggeredBy, $requestId,
                retry: $retryOperationId, proof: $probe->id);
        });
    }

    /** @param Collection<int, ConnServerNode> $servers @return Collection<int, ConnServerNode> */
    private function remainingPromotionNodes(string $pin, Collection $servers): Collection
    {
        $completed = [];
        foreach (Operation::query()->where('kind', self::Kind)->where('input->release_pin', $pin)
            ->where('input->release_stage', 'promotion')->get() as $attempt) {
            $results = (array) ($attempt->result['nodes'] ?? []);
            if (array_column($results, 'node_id') !== array_column((array) data_get($attempt->input, 'intent.nodes', []), 'node_id')) {
                throw new ResourceOperationException('conn_server.promotion_outcome_unknown', 'Confirm which Nodes changed before another promotion can run.', 409);
            }
            foreach ($results as $result) {
                if (($result['outcome'] ?? null) === 'updated') {
                    $completed[] = $result['node_id'];
                }
                if (($result['outcome'] ?? null) === 'failed' && ($result['rolled_back'] ?? false) === false
                    && ($result['changed'] ?? true) === true) {
                    throw new ResourceOperationException('conn_server.promotion_outcome_unknown', 'Confirm the failed Node recovered before retrying.', 409);
                }
            }
        }

        return $servers->whereNotIn('node_id', $completed);
    }

    /** @param Collection<int, ConnServerNode> $servers */
    private function preflight(FolderPin $pin, string $key, Collection $servers, ?string $by, ?string $requestId = null, ?int $retry = null): Operation
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
        $identity = "conn-release:{$key}:preflight:".($retry ?? 'first');
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

    private function pin(): FolderPin
    {
        $pin = $this->recipes->currentRecipe()->instances?->agent['conn-server'] ?? null;
        if ($pin?->macApp === null) {
            throw new ResourceOperationException('conn_server.pin_unavailable', 'The Conn Server pin names no macOS arm64 app.', 409);
        }

        return $pin;
    }

    /** @return Collection<int, ConnServerNode> */
    private function servers(): Collection
    {
        $servers = ConnServerNode::query()->with('node')->orderByDesc('canary')->orderBy('node_id')->get();
        if ($servers->where('canary', true)->count() !== 1) {
            throw new ResourceOperationException('conn_server.canary_unset', 'Exactly one Conn Server Node must be the canary.', 409);
        }

        return $servers;
    }

    /** @return array<string, mixed> */
    private function nodeIntent(ConnServerNode $server): array
    {
        return ['node_id' => $server->node_id, 'ssh' => $this->ssh->forNode($server->node),
            'server' => ['platform' => $server->platform, 'path' => $server->path, 'service' => $server->service, 'port' => $server->port,
                'user' => $server->user, 'canary' => $server->canary, 'notify_before_restart' => $server->notify_before_restart]];
    }

    /** @param Collection<int, ConnServerNode> $servers */
    private function retry(FolderPin $pin, string $key, string $stage, Collection $servers, ?Operation $previous, ?string $by, ?string $requestId, ?int $retryId): Operation
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
    private function releaseOperation(FolderPin $pin, string $key, string $stage, Collection $servers, ?string $by, ?string $requestId = null, ?int $retry = null, ?int $proof = null): Operation
    {
        // Failed attempts remain as evidence. An explicit retry is keyed by the exact failed attempt it replaces.
        $identity = "conn-release:{$key}:{$stage}:".($retry ?? 'first');

        return $this->idempotency->under(self::Kind, $identity, $identity, fn (): Operation => $this->run(
            $pin, $servers, in_array($stage, ['check', 'preflight'], true), $stage === 'canary', $by, $requestId,
            ['release_pin' => $key, 'release_stage' => $stage,
                ...($retry === null ? [] : ['retry_operation_id' => $retry]),
                ...($proof === null ? [] : ['canary_operation_id' => $proof])],
        ));
    }

    /**
     * @param  Collection<int, ConnServerNode>  $servers
     * @param  array<string, int|string>  $release
     */
    private function run(FolderPin $pin, Collection $servers, bool $dryRun, bool $probe, ?string $by, ?string $requestId, array $release = []): Operation
    {
        $mac = $pin->macApp ?? throw new \LogicException('The validated pin has no Mac app.');

        return $this->start->execute(self::Kind, 'fleet', SwampNode::id(), SwampNode::id(), [...$release, 'intent' => [
            'probe_thread' => $probe,
            'stop_on_hold' => ($release['release_stage'] ?? null) === 'promotion',
            'version' => $pin->version,
            'dry_run' => $dryRun,
            'mac' => ['url' => $mac->artifact->location, 'sha256' => $mac->artifact->digest, 'bundle_id' => $mac->bundleId, 'team_id' => $mac->teamId],
            'linux' => ['url' => $pin->artifact->location, 'sha256' => $pin->artifact->digest],
            'notice' => Config::array('orbit.conn.server_rollout_notice'),
            'nodes' => $servers->map($this->nodeIntent(...))->values()->all(),
        ]], $by, $requestId, lockKey: self::Lock);
    }
}
