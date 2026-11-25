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
use Illuminate\Validation\ValidationException;

/**
 * Stores a changed observation. Metrics also keep each new sample time. Doctor facts record newer confirmations
 * of an unchanged sample without moving its original times.
 */
final readonly class RecordNodeFactAction
{
    private const int ClockSkewSeconds = 60;

    /**
     * @param  string  $subject  the item a kind with many items names, such as a checkout path; '' for other kinds
     * @param  array<string, mixed>  $data
     */
    public function execute(Node $node, NodeFactKind $kind, string $subject, CarbonImmutable $observedAt, array $data): bool
    {
        $tracksConfirmation = in_array($kind, [NodeFactKind::Host, NodeFactKind::Units, NodeFactKind::Ufw, NodeFactKind::Packages], true);
        $receivedAt = CarbonImmutable::now();
        $latestAllowed = $receivedAt->addSeconds(self::ClockSkewSeconds);
        if ($tracksConfirmation && $observedAt->greaterThan($latestAllowed)) {
            throw ValidationException::withMessages(['observed_at' => 'The observation time is more than one minute ahead of the Gateway clock.']);
        }
        $rootTime = $kind === NodeFactKind::Host ? self::rootConfirmation($data, $observedAt) : null;
        $data = $kind->stored($data);
        $key = ['node_id' => $node->id, 'kind' => $kind->value, 'subject' => $subject];
        $digest = $kind === NodeFactKind::Host ? null : self::digest($kind, $subject, $observedAt, $data);
        if (! $tracksConfirmation && NodeFact::query()->where($key)->where('digest', $digest)->exists()) {
            return false;
        }

        return DB::transaction(static function () use ($node, $kind, $subject, $key, $observedAt, $receivedAt, $latestAllowed, $data, $digest, $tracksConfirmation, $rootTime): bool {
            $stored = NodeFact::query()->where($key)->first();
            $lastSample = $stored->confirmed_at ?? $stored?->observed_at;
            if ($tracksConfirmation && $lastSample !== null && $lastSample->lessThanOrEqualTo($latestAllowed) && $observedAt->lessThan($lastSample)) {
                return false;
            }

            if ($kind === NodeFactKind::Host) {
                $data = self::keepRoot($stored, $data, $receivedAt);
            }
            $digest ??= self::digest($kind, $subject, $observedAt, $data);

            $confirmation = $tracksConfirmation ? ['confirmed_at' => $observedAt, 'root_confirmed_at' => $rootTime] : [];
            if ($stored !== null && $stored->digest === $digest) {
                if ($tracksConfirmation && ! $observedAt->equalTo($stored->confirmed_at ?? $stored->observed_at)) {
                    $stored->update($confirmation);
                }

                return false;
            }

            ($stored ?? new NodeFact($key))->fill([
                ...$confirmation,
                'digest' => $digest, 'data' => $data, 'observed_at' => $observedAt, 'received_at' => $receivedAt,
            ])->save();
            // A new index removes the checkouts it no longer names, in the same transaction.
            if ($kind === NodeFactKind::Checkouts) {
                NodeFact::query()->where('node_id', $node->id)->where('kind', NodeFactKind::Checkout->value)
                    ->whereNotIn('subject', ValidatedData::stringList($data['paths'] ?? []))->delete();
            }

            return true;
        });
    }

    /** @param array<string, mixed> $data */
    private static function digest(NodeFactKind $kind, string $subject, CarbonImmutable $observedAt, array $data): string
    {
        $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $observation = $kind === NodeFactKind::Metrics ? "\0".$observedAt->toISOString() : '';

        return hash('sha256', $kind->value."\0".$subject."\0".$encoded.$observation);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private static function root(array $data): ?array
    {
        $filesystems = $data['filesystems'] ?? null;
        $root = array_find(is_array($filesystems) ? $filesystems : [], static fn (mixed $fs): bool => is_array($fs) && ($fs['mount'] ?? null) === '/');

        return is_array($root) ? ValidatedData::object($root) : null;
    }

    /** @param array<string, mixed> $data */
    private static function rootConfirmation(array $data, CarbonImmutable $observedAt): ?CarbonImmutable
    {
        $root = self::root($data);
        $at = $root['observed_at'] ?? null;
        if (($root['stale'] ?? null) !== false || ! is_string($at)) {
            return null;
        }

        $sampledAt = CarbonImmutable::parse($at);

        return $sampledAt->lessThanOrEqualTo($observedAt) ? $sampledAt : null;
    }

    /**
     * The agent omits filesystems it has yet to read. Keep the previous root reading and the first time it went
     * missing. Doctor stops trusting a kept reading after {@see FactDoctor::KeptRootSeconds}.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function keepRoot(?NodeFact $stored, array $data, CarbonImmutable $receivedAt): array
    {
        if ($stored === null || ! is_array($data['filesystems'] ?? null) || self::root($data) !== null) {
            return $data;
        }
        $kept = self::root($stored->data);
        if ($kept === null) {
            return $data;
        }

        if (! is_string($kept['kept_since'] ?? null)) {
            $kept = [...$kept, 'observed_at' => $stored->observed_at->toIso8601ZuluString(), 'kept_since' => $receivedAt->toIso8601ZuluString()];
        }
        $data['filesystems'] = [$kept, ...$data['filesystems']];

        return $data;
    }
}
