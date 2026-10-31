<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Shared\ResourceOperationException;
use App\Models\InstanceRemoval;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Persist the native source capture once; retries reuse the original identity after quarantine. */
final readonly class InstanceDestroySourceInspection
{
    /** @param array<string, mixed> $inspection
     * @return array<string, mixed>
     */
    public function capture(Operation $operation, array $inspection): array
    {
        return $this->captureInput($operation->input ?? [], $inspection);
    }

    /** Capture within an owning parent's saved removal, without a child Operation.
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $inspection
     * @return array<string, mixed>
     */
    public function captureInput(array $input, array $inspection): array
    {
        return DB::transaction(function () use ($input, $inspection): array {
            $removal = InstanceRemoval::query()->lockForUpdate()->findOrFail(ValidatedData::string($input['removal_id']));
            if ($removal->native_sources !== null) {
                $original = $removal->native_sources;
                $additions = collect(InstanceLifecycleInput::members($inspection))->keyBy('instance_id');
                $original['members'] = array_map(static function (array $member) use ($additions): array {
                    $expanded = $additions->get(ValidatedData::integer($member['instance_id']));

                    return is_array($expanded) ? [...$expanded, ...$member] : $member;
                }, InstanceLifecycleInput::members($original));
                $removal->update(['native_sources' => $original]);

                return $this->executable($input);
            }
            $members = InstanceLifecycleInput::members($inspection);
            $records = $removal->members()->get()->keyBy('instance_id');
            if (count($members) !== $records->count() || count(array_unique(ValidatedData::integerList(array_column($members, 'instance_id')))) !== count($members)) {
                $this->conflict();
            }
            foreach ($members as $member) {
                $record = $records->get(ValidatedData::integer($member['instance_id']));
                if ($record === null) {
                    $this->conflict();
                }
                $record->update([
                    'source_digest' => ValidatedData::string($member['source_digest']),
                    'source_commit' => ValidatedData::string($member['source_commit']),
                    'common_repository_path' => ValidatedData::string($member['common_repository_path']),
                    'source_identity' => ValidatedData::string($member['source_identity']),
                    'linked_worktree_paths' => ValidatedData::stringList($member['linked_worktree_paths']),
                    'worktree_inventory' => ValidatedData::string($member['worktree_inventory']),
                    'origin' => ValidatedData::string($member['origin']),
                ]);
            }
            $removal->update(['native_sources' => $inspection]);

            return $this->executable($input);
        });
    }

    /** Pending placeholders never become executable until the native source capture exists.
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function executable(array $input): array
    {
        if (($input['source_pending'] ?? false) !== true) {
            return $input;
        }
        $removal = InstanceRemoval::query()->findOrFail(ValidatedData::string($input['removal_id']));
        $inspection = $removal->native_sources;
        if ($inspection === null) {
            return $input;
        }
        $captured = collect(InstanceLifecycleInput::members($inspection))->keyBy('instance_id');
        $input['members'] = array_map(function (array $member) use ($captured): array {
            $source = $captured->get(ValidatedData::integer($member['instance_id']));
            if (! is_array($source)) {
                $this->conflict();
            }

            return [...$member, ...$source];
        }, InstanceLifecycleInput::members($input));
        $detail = ValidatedData::object($input['direct_destroy']);
        $detail['members'] = array_map(function (array $member) use ($captured): array {
            $source = $captured->get(ValidatedData::integer($member['instance_id']));
            if (! is_array($source)) {
                $this->conflict();
            }
            $base = ValidatedData::object($member['source']);
            $digest = ValidatedData::string($source['source_digest']);

            return [...$member, 'source' => [...$base,
                'root' => $source['source_root'], 'user' => $source['user'], 'group' => $source['group'],
                'digest' => $digest, 'common_repository' => $source['common_repository_path'],
                'source_identity' => $source['source_identity']],
                'origin' => $source['origin'], 'worktrees' => $source['worktree_inventory'], 'commit' => $source['source_commit'],
                'receipt' => hash('sha256', ValidatedData::string($base['operation'])."\0".ValidatedData::string($base['member'])."\0".$digest."\0finalized"),
            ];
        }, InstanceLifecycleInput::members(['members' => $detail['members']]));
        $input['direct_destroy'] = $detail;
        $input['source_pending'] = false;

        return $input;
    }

    private function conflict(): never
    {
        throw new ResourceOperationException('instance.removal_conflict', 'The native inspection does not match the admitted removal members.', 409);
    }
}
