<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

/** Fleet issue ownership and its stored repair mapping. Swamp owns verification. */
final readonly class FleetNodeVerifier
{
    /** @var list<string> */
    public const array OwnedIssues = [
        'node.ssh_unreachable',
        'node.agent_missing',
        'node.agent_binary_mismatch',
        'node.agent_inactive',
        'node.agent_secret_mismatch',
        'role.caddy_build_drift',
        'node.cli_foreign',
    ];

    /**
     * The footprint artifact that repairs each owned issue. When the baseline already shows one, the step
     * re-applies that artifact even though its digest matches, so the verify does not fail on a Node that a
     * re-apply would fix.
     *
     * @var array<string, string>
     */
    public const array RepairingArtifacts = [
        'node.agent_missing' => 'agent',
        'node.agent_binary_mismatch' => 'agent',
        'node.agent_inactive' => 'agent',
        'node.agent_secret_mismatch' => 'agent',
        'role.caddy_build_drift' => 'caddy',
    ];

    /**
     * The artifacts to force for the owned issues in a baseline.
     *
     * @param  list<string>  $baseline
     * @return list<string>
     */
    public static function repairs(array $baseline): array
    {
        $artifacts = [];

        foreach ($baseline as $key) {
            $code = explode(':', $key, 2)[0];

            if (isset(self::RepairingArtifacts[$code])) {
                $artifacts[] = self::RepairingArtifacts[$code];
            }
        }

        return array_values(array_unique($artifacts));
    }
}
