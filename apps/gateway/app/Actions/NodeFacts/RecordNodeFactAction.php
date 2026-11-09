<?php

declare(strict_types=1);

namespace App\Actions\NodeFacts;

use App\Domain\NodeFacts\NodeFactKind;
use App\Models\Node;
use App\Models\NodeFact;
use App\Support\ValidatedData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Stores a changed observation. Metrics also retain a new observation time when the values match.
 * Unchanged resyncs read one row and write nothing. A change compares and writes in one short transaction.
 */
final readonly class RecordNodeFactAction
{
    /**
     * @param  string  $subject  the item a kind with many items names, such as a checkout path; '' for other kinds
     * @param  array<string, mixed>  $data
     */
    public function execute(Node $node, NodeFactKind $kind, string $subject, CarbonImmutable $observedAt, array $data): bool
    {
        $data = $kind->stored($data);
        $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // A new metrics reading keeps its time even when its values happen to match the last reading.
        $observation = $kind === NodeFactKind::Metrics ? "\0".$observedAt->toISOString() : '';
        $digest = hash('sha256', $kind->value."\0".$subject."\0".$encoded.$observation);
        $key = ['node_id' => $node->id, 'kind' => $kind->value, 'subject' => $subject];
        $stored = static fn (): ?string => ValidatedData::nullableString(NodeFact::query()->where($key)->value('digest'));
        if ($stored() === $digest) {
            return false;
        }

        return DB::transaction(static function () use ($node, $kind, $key, $observedAt, $data, $digest, $stored): bool {
            if ($stored() === $digest) {
                return false;
            }
            NodeFact::query()->updateOrCreate($key, [
                'digest' => $digest, 'data' => $data, 'observed_at' => $observedAt, 'received_at' => CarbonImmutable::now(),
            ]);
            // A new index removes the checkouts it no longer names, in the same transaction.
            if ($kind === NodeFactKind::Checkouts) {
                NodeFact::query()->where('node_id', $node->id)->where('kind', NodeFactKind::Checkout->value)
                    ->whereNotIn('subject', ValidatedData::stringList($data['paths'] ?? []))->delete();
            }

            return true;
        });
    }
}
