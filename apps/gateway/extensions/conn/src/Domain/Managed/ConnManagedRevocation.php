<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain\Managed;

use App\Domain\Nodes\NodeAccessRemovalTransactionHook;
use App\Models\NodeAccess;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\Conn\Models\ConnManagedRevision;
use Orbit\Extensions\Conn\Models\ConnManagedTarget;
use Orbit\Extensions\Conn\Models\ConnManagedToken;

/**
 * Records a direct edge removal for every managed target of the edge's serving Node in the removal's own transaction,
 * also while the switch is off, and publishes each target's restrictive notice only after the outermost commit
 * ([Revision order](/reference/conn#revision-order)). A rollback records and publishes nothing.
 */
final readonly class ConnManagedRevocation implements NodeAccessRemovalTransactionHook
{
    public function __construct(private ConnManagedNoticePublisher $publisher) {}

    public function edgeDeleted(NodeAccess $edge): void
    {
        $committedAt = ConnManagedSecrets::now();
        $targets = ConnManagedTarget::query()->where('node_id', $edge->serving_node_id)->orderBy('id')->get();

        foreach ($targets as $target) {
            $target->revision++;
            $target->save();
            $row = ConnManagedRevision::query()->create([
                'conn_managed_target_id' => $target->id,
                'generation' => $target->generation,
                'revision' => $target->revision,
                'edge_ids' => [$edge->id],
                'committed_at' => $committedAt,
            ]);
            ConnManagedToken::query()
                ->where('conn_managed_target_id', $target->id)
                ->where('node_access_id', $edge->id)
                ->whereNull('revoked_revision')
                ->update(['revoked_revision' => $target->revision]);

            DB::afterCommit(function () use ($row): void {
                $this->publish($row->id, ConnManagedSecrets::now());
            });
        }
    }

    /**
     * Sends the notice on the captured generation's channel, or records its suppression when a new generation replaced it.
     * `$observedAt` is sampled in the after-commit callback, so it bounds the commit from above as `committed_at` bounds it
     * from below. The generation check, the send and its record hold one write lock, so a rotation commits either before
     * the check, and the notice is suppressed, or after the send.
     */
    private function publish(int $revisionId, string $observedAt): void
    {
        DB::transaction(function () use ($revisionId, $observedAt): void {
            $row = ConnManagedRevision::query()->findOrFail($revisionId);
            $target = ConnManagedTarget::query()->findOrFail($row->conn_managed_target_id);
            $row->commit_observed_at = $observedAt;

            if ($target->generation !== $row->generation) {
                $row->forceFill(['notice_suppressed_at' => ConnManagedSecrets::now()])->save();

                return;
            }

            $sent = $this->publisher->publish($target->channel($row->generation), [
                'environment_id' => $target->environment_id,
                'generation' => $row->generation,
                'revision' => $row->revision,
                'edge_ids' => $row->edge_ids,
            ]);
            if ($sent) {
                $row->notice_sent_at = ConnManagedSecrets::now();
            }
            $row->save();
        });
    }
}
