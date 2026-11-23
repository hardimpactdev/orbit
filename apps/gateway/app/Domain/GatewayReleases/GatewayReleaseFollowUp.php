<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

use App\Models\GatewayRelease;

/**
 * Work an extension owes each serving release, after its fleet phase settles, as its own phase of the release record
 * ([Follow-up phases](/reference/gateway-recovery#follow-up-phases)). The release pass calls it until it settles.
 */
interface GatewayReleaseFollowUp
{
    /** The phase's key in the release record: lowercase letters and underscores. */
    public function phase(): string;

    /**
     * Advances the phase by one step from its last record and returns the new one. Its `status` is `pending` while the pass must come
     * back, and any other value once it settled; `failed` carries an `error_code`. A refusal of its own work is a status, not a throw.
     *
     * @param  array<string, mixed>  $previous
     * @return array<string, mixed>
     */
    public function advance(GatewayRelease $release, array $previous): array;
}
