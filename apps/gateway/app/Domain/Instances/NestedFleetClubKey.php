<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Infrastructure\Swamp\SwampCommandStep;

/**
 * The Swamp Club key of a nested fleet's Gateway (docs/reference/development-containers.md#the-club-key-of-the-nested-gateway).
 * Orbit delivers it at each start of a nested-fleet container into the container's `/run`, a tmpfs, so no snapshot, seed, clone
 * copy or export of the container holds it. The value lives in the Gateway environment (`ORBIT_NESTED_FLEET_CLUB_API_KEY`); a run
 * reads it from its one Swamp vault copy and gets it on stdin only. It is never the proof Club key.
 */
final readonly class NestedFleetClubKey
{
    /** The one Swamp vault entry: a run's execution copy, replaced when the Gateway's value changes. */
    public const string VaultKey = 'nested-fleet-club-api-key';

    public const string Directory = '/run/orbit-club';

    public const string File = 'swamp-club-api-key';

    /**
     * The public delivery description in a nested-fleet container's intent: where the key goes, its owner, and the nested Gateway
     * whose Swamp serve reads it. Normal containers have none.
     *
     * `tmpfiles` makes the empty folder at each boot, before the nested Gateway's disk device needs it; it holds no secret.
     *
     * @return array{directory:string,file:string,owner:int,group:int,tmpfiles:string,gateway:string,serve_unit:string}
     */
    public static function intent(): array
    {
        return ['directory' => self::Directory, 'file' => self::File, 'owner' => 1000, 'group' => 1000,
            'tmpfiles' => '/etc/tmpfiles.d/orbit-club.conf', 'gateway' => 'nested-gateway', 'serve_unit' => 'orbit-swamp-serve'];
    }

    /** Whether a nested-fleet start gets the key: `missing` starts the container without it (the Operation's input says so). */
    public static function status(): string
    {
        return self::value() === null ? 'missing' : 'delivered';
    }

    /**
     * A run's container intent and its vault reference to the key. Only a container whose intent has `club_key` takes part: it gets
     * `club_key.available`, true with a reference when the Gateway has a value. Swamp picks the vault key from that flag, never by testing
     * `execution_secrets` (docs/reference/swamp-command-steps.md#secret-inputs). Any other container comes back unchanged.
     *
     * @param  array<string,mixed>|null  $container
     * @return array{container:array<string,mixed>|null,copies:array<string,array{vault:string,key:string}>}
     */
    public static function forRun(?array $container): array
    {
        if ($container === null || ! is_array($container['club_key'] ?? null)) {
            return ['container' => $container, 'copies' => []];
        }
        $value = self::value();
        $container['club_key']['available'] = $value !== null;

        return ['container' => $container, 'copies' => $value === null ? []
            : ['club_key' => app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', self::VaultKey, $value)]];
    }

    private static function value(): ?string
    {
        $value = config('swamp.nested_fleet_club_api_key');

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
