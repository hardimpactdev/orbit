<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Tasks\SwampReaderEnvironment;

/**
 * The Swamp Club key of a nested fleet's Gateway (docs/reference/development-containers.md#the-club-key-of-the-nested-gateway).
 * Orbit delivers it at each start of a nested-fleet container into the container's `/run`, a tmpfs, so no snapshot, seed, clone
 * copy or export of the container holds it. The value lives in the shared Gateway env file (`ORBIT_NESTED_FLEET_CLUB_API_KEY`); a run
 * reads it from its one Swamp vault copy and gets it on stdin only. It is never the proof Club key. The Operation's result says what
 * the run did with it (`outcome`), never what the Gateway held when it admitted the Operation.
 */
final readonly class NestedFleetClubKey
{
    /** The one Swamp vault entry: a run's execution copy, replaced when the Gateway's value changes. */
    public const string VaultKey = 'nested-fleet-club-api-key';

    public const string Directory = '/run/orbit-club';

    public const string File = 'swamp-club-api-key';

    /** The shared Gateway env setting that `gateway:nested-fleet-club-key:install` writes. */
    public const string Setting = 'ORBIT_NESTED_FLEET_CLUB_API_KEY';

    /** The codes of a start that refused to deliver the key: it wrote nothing. */
    private const array Refusals = ['instance.nested_club_key_unsafe_transfer', 'instance.nested_club_key_not_tmpfs'];

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

    /**
     * The Operation's `nested_fleet_club_key` from what its start did: `written`, `written_serve_not_restarted` (the inner Incus was not
     * ready, so serve reads the key when it next starts by itself) or `unchanged` in the delivery script's own word (`changed`,
     * `changed_serve_pending`, `unchanged`), `refused` for a refusal before the write, and `missing` when the start wrote nothing for any
     * other reason: no value at the run's start, a failed write, or a run that ended before the step.
     */
    public static function outcome(mixed $word, mixed $errorCode): string
    {
        return match (true) {
            $word === 'changed' => 'written',
            $word === 'changed_serve_pending' => 'written_serve_not_restarted',
            $word === 'unchanged' => 'unchanged',
            in_array($errorCode, self::Refusals, true) => 'refused',
            default => 'missing',
        };
    }

    /**
     * A run's container intent and its vault reference to the key. Only a container whose intent has `club_key` takes part: it gets
     * `club_key.available`, true with a reference when the Gateway has a value. This is the Operation's one read of the value: the flag
     * and the vault copy follow from it. Swamp picks the vault key from that flag, never by testing `execution_secrets`
     * (docs/reference/swamp-command-steps.md#secret-inputs). Any other container comes back unchanged and reads nothing.
     *
     * @param  array<string,mixed>|null  $container
     * @return array{container:array<string,mixed>|null,copies:array<string,array{vault:string,key:string}>}
     */
    public static function forRun(?array $container): array
    {
        if ($container === null || ! is_array($container['club_key'] ?? null)) {
            return ['container' => $container, 'copies' => []];
        }
        $reference = self::reference();
        $container['club_key']['available'] = $reference !== null;

        return ['container' => $container, 'copies' => $reference === null ? [] : ['club_key' => $reference]];
    }

    /**
     * The run's one read of the value, as the vault reference to the one entry, or null when the Gateway holds no value. A task
     * copy's publish reads it too: its content check refuses a commit that holds the key (docs/reference/tasks.md#task-copy).
     *
     * @return array{vault:string,key:string}|null
     */
    public static function reference(): ?array
    {
        $value = self::value();

        return $value === null ? null : app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', self::VaultKey, $value);
    }

    /**
     * The value, read at the run's start from the shared env file that the installer writes, never from the configuration that a
     * long-running queue worker loaded when it booted. So a placed or changed key takes effect at the next start, with no reload.
     * A Gateway with no shared env file has no value.
     */
    private static function value(): ?string
    {
        if (! is_file(app(GatewayReleaseLayout::class)->environmentPath())) {
            return null;
        }
        $environment = app(SwampReaderEnvironment::class);
        $value = $environment->values($environment->contents())[self::Setting] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
