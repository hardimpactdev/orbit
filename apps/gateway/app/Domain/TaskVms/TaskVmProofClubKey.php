<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\TaskVm;

/**
 * The swamp-club collective key for VMs of the canonical `swamp-proof` Project.
 * `bin/swamp-proof` creates every proof task group in that Project, so the
 * stored Project slug is the marker; other groups never receive the key.
 */
final readonly class TaskVmProofClubKey
{
    public const string ProjectSlug = 'swamp-proof';

    public const string Vault = 'orbit-control-inputs';

    public const string KeyPattern = '^task-vm-proof-club-key-[1-9][0-9]*$';

    public function __construct(private SwampCommandStep $shared) {}

    /** Names-only descriptor for a proof VM; empty for any other VM.
     * @return array<string,array{vault:string,key:string}>
     */
    public function capture(TaskVm $vm): array
    {
        if (! $this->applies($vm)) {
            return [];
        }
        $value = config('swamp.proof_club_api_key');
        if (! is_string($value) || trim($value) === '') {
            throw new TaskVmException(TaskVmError::ProofClubKeyMissing,
                'Proof VMs need the swamp-club collective key. Set ORBIT_SWAMP_PROOF_CLUB_API_KEY on the Gateway.', 503);
        }

        return ['proof_club_key' => $this->shared->executionCopy(self::Vault, self::key($vm), trim($value))];
    }

    /** Destroy admission deletes the copy; the guest file dies with the VM disk. */
    public function remove(TaskVm $vm): void
    {
        if ($this->applies($vm)) {
            $this->shared->removeExecutionCopy(self::Vault, self::key($vm));
        }
    }

    private function applies(TaskVm $vm): bool
    {
        return $vm->group?->project?->slug === self::ProjectSlug;
    }

    private static function key(TaskVm $vm): string
    {
        return 'task-vm-proof-club-key-'.$vm->id;
    }
}
