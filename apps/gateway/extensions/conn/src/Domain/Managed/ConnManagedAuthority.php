<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain\Managed;

use App\Domain\Shared\ResourceOperationException;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Models\Node;
use App\Models\NodeAccess;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\Conn\Models\ConnManagedRevision;
use Orbit\Extensions\Conn\Models\ConnManagedTarget;
use Orbit\Extensions\Conn\Models\ConnManagedToken;
use SensitiveParameter;

/**
 * The verified state a managed target reads ([Authority state](/reference/conn#authority-state),
 * [Token claims](/reference/conn#token-claims)) and the acknowledgement it sends
 * ([Fence and acknowledgement](/reference/conn#fence-and-acknowledgement)).
 */
final readonly class ConnManagedAuthority
{
    /** @return array<string, mixed> */
    public function state(ConnManagedTarget $target, string $nonce, ?int $afterRevision): array
    {
        // One transaction, so head, edges, revisions and owed come from one committed state.
        return DB::transaction(fn (): array => $this->readState($target, $nonce, $afterRevision));
    }

    /** @return array<string, mixed> */
    private function readState(ConnManagedTarget $target, string $nonce, ?int $afterRevision): array
    {
        $target = ConnManagedTarget::query()->findOrFail($target->id);
        $head = $target->revision;
        if ($afterRevision !== null && $afterRevision > $head) {
            throw new ResourceOperationException('conn.managed_revision_future', 'after_revision is above the head.', 409, details: ['head_revision' => $head]);
        }

        $state = [
            ...$this->echo($target, $nonce),
            'head_revision' => $head,
            'server_time' => ConnManagedSecrets::now(),
            'kind' => match (true) {
                $afterRevision === null => 'snapshot',
                $afterRevision < $head => 'delta',
                default => 'current',
            },
        ];
        if ($afterRevision === null) {
            $state['edges'] = NodeAccess::query()->where('serving_node_id', $target->node_id)->orderBy('id')->get()
                ->map(static fn (NodeAccess $edge): array => ['edge_id' => $edge->id, 'consumer_node_id' => $edge->consumer_node_id])->all();
        } elseif ($afterRevision < $head) {
            $state['revisions'] = $target->revisions()->where('revision', '>', $afterRevision)->orderBy('revision')->get()
                ->map(static fn (ConnManagedRevision $row): array => ['revision' => $row->revision, 'edge_ids' => $row->edge_ids])->all();
        }
        $state['owed'] = $target->revisions()->where('generation', $target->generation)->whereNull('acknowledged_at')->whereNull('superseded_at')->orderBy('revision')->get()
            ->map(static fn (ConnManagedRevision $row): array => ['revision' => $row->revision, 'edge_ids' => $row->edge_ids])->all();

        return $state;
    }

    /** @return array<string, mixed> */
    public function introspect(ConnManagedTarget $target, string $nonce, #[SensitiveParameter] string $token, string $peerAddress): array
    {
        $record = str_starts_with($token, ConnManagedSecrets::TokenPrefix)
            ? ConnManagedToken::query()->where('token_digest', ConnManagedSecrets::digest($token))->first()
            : null;
        $target = ConnManagedTarget::query()->findOrFail($target->id);

        if (! $record instanceof ConnManagedToken
            || $record->conn_managed_target_id !== $target->id
            || $record->generation !== $target->generation
            || $record->revoked_revision !== null
            || ! $record->expires_at->isFuture()
            || ! NodeAccess::query()->whereKey($record->node_access_id)->exists()) {
            throw new ResourceOperationException('conn.managed_token_invalid', 'The managed token is not valid for this target.', 403);
        }

        $caller = RequireActiveWireGuardPeer::activeNodeAt($peerAddress);
        if (! $caller instanceof Node || $caller->id !== $record->caller_node_id) {
            throw new ResourceOperationException('conn.managed_peer_mismatch', 'The socket peer is not the token\'s caller.', 403);
        }

        return [
            ...$this->echo($target, $nonce),
            'token_id' => $record->id,
            'caller_node_id' => $record->caller_node_id,
            'edge_id' => $record->node_access_id,
            'scopes' => $record->scopes,
            'expires_at' => $record->expires_at->utc()->format('Y-m-d\TH:i:s.up'),
            'revision' => $target->revision,
        ];
    }

    /**
     * Settles the owed row of this exact environment, generation and revision on its first valid ACK. A retry answers
     * the first result and changes nothing.
     *
     * @param  array{revision: int, kind: string, notice_received_at: ?string, fenced_at: ?string, handlers_closed_at: ?string, recovered_at: ?string}  $ack
     * @return array<string, mixed>
     */
    public function acknowledge(ConnManagedTarget $target, array $ack): array
    {
        return DB::transaction(function () use ($target, $ack): array {
            $target = ConnManagedTarget::query()->findOrFail($target->id);
            $row = $target->revisions()->where('revision', $ack['revision'])->first();
            if (! $row instanceof ConnManagedRevision) {
                throw new ResourceOperationException('conn.managed_ack_unknown', 'The ACK names no owed revision.', 409);
            }
            if ($row->generation !== $target->generation) {
                throw new ResourceOperationException('conn.managed_generation_mismatch', 'The revision belongs to a replaced generation.', 409);
            }
            if ($row->acknowledged_at === null) {
                $row->forceFill([
                    'ack_kind' => $ack['kind'],
                    'acknowledged_at' => ConnManagedSecrets::now(),
                    'notice_received_at' => $ack['notice_received_at'],
                    'fenced_at' => $ack['fenced_at'],
                    'handlers_closed_at' => $ack['handlers_closed_at'],
                    'recovered_at' => $ack['recovered_at'],
                ])->save();
            }

            return [
                'environment_id' => $target->environment_id,
                'generation' => $row->generation,
                'revision' => $row->revision,
                'kind' => $row->ack_kind,
                'acknowledged_at' => $row->acknowledged_at,
            ];
        });
    }

    /** @return array{nonce: string, environment_id: string, generation: int} */
    private function echo(ConnManagedTarget $target, string $nonce): array
    {
        return ['nonce' => $nonce, 'environment_id' => $target->environment_id, 'generation' => $target->generation];
    }
}
