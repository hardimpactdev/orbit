<?php

declare(strict_types=1);

namespace App\Actions\NodeFacts;

use App\Domain\Doctor\FactDoctor;
use App\Domain\NodeFacts\NodeFactKind;
use App\Models\Node;
use App\Models\NodeFact;
use App\Support\ValidatedData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Stores a changed observation. Metrics also retain a new observation time when the values match.
 * Unchanged resyncs read one row and write nothing. A change compares and writes in one short transaction.
 * A `host` report without a reading for `/` keeps the stored `/` reading, marked with when it was first kept.
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
        if ($kind === NodeFactKind::Host) {
            $data = self::keepRoot($node, $data);
        }
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

    /**
     * The agent omits a filesystem it has not read yet, so its first `host` report after a start can lack `/`. That
     * means "no reading yet", not "no root filesystem": the stored `/` reading stays. It carries `observed_at`, the
     * observation time of the stored row that held it (a row is written only on a change, so the reading was confirmed
     * then or later), and `kept_since`, when the Gateway received the first report without it.
     * A later reading of `/` replaces it. Doctor stops trusting a kept reading after {@see FactDoctor::KeptRootSeconds}.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function keepRoot(Node $node, array $data): array
    {
        $root = static fn (mixed $filesystems): mixed => array_find(is_array($filesystems) ? $filesystems : [], static fn (mixed $fs): bool => is_array($fs) && ($fs['mount'] ?? null) === '/');
        if (! is_array($data['filesystems'] ?? null) || $root($data['filesystems']) !== null) {
            return $data;
        }
        $stored = NodeFact::query()->where(['node_id' => $node->id, 'kind' => NodeFactKind::Host->value, 'subject' => ''])->first();
        $kept = $stored === null ? null : $root($stored->data['filesystems'] ?? null);
        if ($stored === null || ! is_array($kept)) {
            return $data;
        }
        $zulu = static fn (CarbonImmutable $at): string => $at->utc()->format('Y-m-d\TH:i:s\Z');
        // A reading kept before keeps its first times, so a retried or repeated report without `/` cannot renew it.
        if (! is_string($kept['kept_since'] ?? null)) {
            $kept = [...$kept, 'observed_at' => $zulu(CarbonImmutable::instance($stored->observed_at)), 'kept_since' => $zulu(CarbonImmutable::now())];
        }
        $data['filesystems'] = [$kept, ...$data['filesystems']];

        return $data;
    }
}
