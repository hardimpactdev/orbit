<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestCommandResult;
use App\E2E\Value\RoleImage;
use App\E2E\Value\TopologyTarget;
use RuntimeException;
use Throwable;

/** Reads the role images of the harness project: what `acquire` starts guests from, and their freshness. */
final readonly class RoleImages
{
    public const string PROGRAMS_PROPERTY = 'orbit.e2e.programs';

    public const string COMMIT_PROPERTY = 'orbit.e2e.commit';

    public function __construct(
        private IncusHost $host,
        private RoleImagePrograms $programs,
    ) {}

    /**
     * The fingerprint each alias names now. A missing image fails with the build command.
     *
     * @param  list<RoleImage>  $images
     * @return array<string, string> role image value => fingerprint
     */
    public function resolve(array $images): array
    {
        $fingerprints = [];
        foreach ($images as $image) {
            try {
                $fingerprints[$image->value] = $this->host->imageFingerprint($image->alias(), $this->instanceType($image));
            } catch (RuntimeException $exception) {
                throw new RuntimeException(
                    "The role image [{$image->alias()}] is missing; run bin/e2e-topology-images build --role={$image->value}.",
                    previous: $exception,
                );
            }
        }

        return $fingerprints;
    }

    /** Recipe roles are VMs; the legacy standalone harness keeps its operator container. */
    public function instanceType(RoleImage $role): string
    {
        return $this->usesRecipe($role) ? 'virtual-machine' : $role->instanceType();
    }

    public function usesRecipe(RoleImage $role): bool
    {
        return array_any($this->host->images(), static fn (array $image): bool => in_array($role->alias(), $image['aliases'], true)
            && $image['type'] === 'virtual-machine'
            && preg_match('/\Asha256:[a-f0-9]{64}\z/D', $image['properties']['orbit.recipe'] ?? '') === 1
            && ($image['properties']['orbit.profile'] ?? null) === $role->value);

    }

    /** Recipe images retain cloud-init; wait through its seed reboot before configuring a topology. */
    public function settle(TopologyTarget $target): void
    {
        $inventory = $this->host->images();
        $guests = [];
        foreach ($target->recipe->nodes as $node) {
            if (array_any($inventory, static fn (array $image): bool => in_array($node->image->alias(), $image['aliases'], true) && isset($image['properties']['orbit.recipe']))) {
                $guests[] = $target->instance($node->key);
            }
        }
        if ($guests === []) {
            return;
        }
        $probe = 'case "$(cloud-init status 2>/dev/null)" in *done*|*disabled*) ;; *) exit 1 ;; esac; cat /proc/sys/kernel/random/boot_id';
        $previous = null;
        $deadline = microtime(true) + 900;
        do {
            $commands = [];
            foreach ($guests as $guest) {
                $commands[$guest] = ['instance' => $guest, 'command' => new GuestCommand(['sh', '-c', $probe], 15)];
            }
            try {
                $boots = array_map(static fn (GuestCommandResult $result): ?string => $result->successful() && trim($result->stdout) !== '' ? trim($result->stdout) : null, $this->host->execAll($commands));
            } catch (RuntimeException) {
                $boots = [];
            }
            if ($boots !== [] && ! in_array(null, $boots, true) && $boots === $previous) {
                return;
            }
            $previous = $boots;
            usleep(1_000_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('The imported recipe guests did not finish their first boot.');
    }

    /**
     * Each role image: `current` when it ran the programs the checkout renders now, `stale` when they
     * changed, or `missing`.
     *
     * @return list<array{role:string,alias:string,state:string,fingerprint:?string,created_at:?string,commit:?string,error:?string}>
     */
    public function status(): array
    {
        $inventory = $this->host->images();
        $rows = [];
        foreach (RoleImage::cases() as $image) {
            $matches = array_values(array_filter(
                $inventory,
                static fn (array $candidate): bool => in_array($image->alias(), $candidate['aliases'], true),
            ));
            if (count($matches) !== 1) {
                $rows[] = ['role' => $image->value, 'alias' => $image->alias(), 'state' => 'missing', 'fingerprint' => null, 'created_at' => null, 'commit' => null, 'error' => null];

                continue;
            }
            $found = $matches[0];
            $error = null;
            try {
                $current = RoleImagePrograms::listFingerprint($this->programs->for($image));
                $state = ($found['properties'][self::PROGRAMS_PROPERTY] ?? null) === $current ? 'current' : 'stale';
            } catch (Throwable $exception) {
                $state = 'unknown';
                $error = $exception->getMessage();
            }
            $rows[] = [
                'role' => $image->value,
                'alias' => $image->alias(),
                'state' => $state,
                'fingerprint' => $found['fingerprint'],
                'created_at' => $found['created_at'],
                'commit' => $found['properties'][self::COMMIT_PROPERTY] ?? null,
                'error' => $error,
            ];
        }

        return $rows;
    }
}
