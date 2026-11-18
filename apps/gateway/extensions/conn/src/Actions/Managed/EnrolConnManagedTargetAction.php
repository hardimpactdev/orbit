<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Actions\Managed;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\Conn\Domain\Managed\ConnManagedSecrets;
use Orbit\Extensions\Conn\Domain\Managed\ConnManagedTargetLock;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnManagedRevision;
use Orbit\Extensions\Conn\Models\ConnManagedTarget;
use RuntimeException;

/**
 * Enrols a registered environment as a managed target, or replaces its generation, and writes the new service
 * credential to a mode-0600 file ([Enrol a lab target](/reference/conn#enrol-a-lab-target)). The credential is never returned.
 */
final readonly class EnrolConnManagedTargetAction
{
    public const string CanonicalUuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** @return array{target_id: int, environment_id: string, generation: int, revision: int, node_id: int} */
    public function execute(string $environmentId, string $credentialFile): array
    {
        if (preg_match(self::CanonicalUuid, $environmentId) !== 1) {
            throw new ResourceOperationException('conn.managed_environment_id_invalid', 'The environment ID is not a lowercase canonical UUID.', 422);
        }
        $environment = ConnEnvironment::query()->where('environment_id', $environmentId)->with('node')->first();
        if (! $environment instanceof ConnEnvironment || $environment->node?->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException('conn.managed_target_unknown', 'The environment is not registered on an active Node.', 404);
        }
        $nodeId = $environment->node->id;

        if (is_dir($credentialFile)) {
            throw new ResourceOperationException('conn.managed_credential_file_invalid', 'The credential file path is a directory.', 422);
        }

        // A rotation takes the target's lock, so it never lands between a notice's generation check, send and record.
        $existing = ConnManagedTarget::query()->where('conn_environment_id', $environment->id)->value('id');
        if (! is_int($existing)) {
            return $this->enrol($environment, $nodeId, $credentialFile);
        }

        return ConnManagedTargetLock::run(
            $existing,
            fn (): array => $this->enrol($environment, $nodeId, $credentialFile),
            static fn (): never => throw new ResourceOperationException('conn.managed_target_busy', 'A revocation notice of this target is being sent; enrol again.', 409),
        );
    }

    /** @return array{target_id: int, environment_id: string, generation: int, revision: int, node_id: int} */
    private function enrol(ConnEnvironment $environment, int $nodeId, string $credentialFile): array
    {
        $credential = ConnManagedSecrets::service();
        $temporary = $credentialFile.'.'.bin2hex(random_bytes(6)).'.tmp';
        // One cleanup for every outcome: after a delivery the temporary file no longer exists, after any failure it goes.
        try {
            $previous = umask(0077);
            try {
                if (@file_put_contents($temporary, $credential) === false || ! @chmod($temporary, 0600)) {
                    throw new RuntimeException('The credential file could not be written.');
                }
            } finally {
                umask($previous);
            }

            // The file is delivered as the last step before the commit: a failed delivery rolls the rotation back, so the
            // old credential and owed rows stay valid. A commit that fails after delivery leaves a file the Gateway refuses.
            $target = DB::transaction(function () use ($environment, $nodeId, $credential, $temporary, $credentialFile): ConnManagedTarget {
                $target = ConnManagedTarget::query()->where('conn_environment_id', $environment->id)->first();
                if (! $target instanceof ConnManagedTarget) {
                    $target = ConnManagedTarget::query()->create([
                        'conn_environment_id' => $environment->id, 'environment_id' => $environment->environment_id, 'node_id' => $nodeId,
                        'generation' => 1, 'revision' => 0, 'credential_digest' => ConnManagedSecrets::digest($credential),
                    ]);
                    self::deliver($temporary, $credentialFile);

                    return $target;
                }
                // The old generation's owed rows can never be acknowledged now ([Revision order](/reference/conn#revision-order)).
                ConnManagedRevision::query()->where('conn_managed_target_id', $target->id)->whereNull('acknowledged_at')->whereNull('superseded_at')
                    ->update(['superseded_at' => ConnManagedSecrets::now()]);
                $target->forceFill([
                    'node_id' => $nodeId, 'generation' => $target->generation + 1, 'credential_digest' => ConnManagedSecrets::digest($credential),
                ])->save();
                self::deliver($temporary, $credentialFile);

                return $target;
            });
        } finally {
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }

        return ['target_id' => $target->id, 'environment_id' => $target->environment_id, 'generation' => $target->generation, 'revision' => $target->revision, 'node_id' => $target->node_id];
    }

    private static function deliver(string $temporary, string $credentialFile): void
    {
        if (! @rename($temporary, $credentialFile)) {
            throw new RuntimeException('The credential file could not be delivered.');
        }
    }
}
