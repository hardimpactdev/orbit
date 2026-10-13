<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\RoleImage;
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
                $fingerprints[$image->value] = $this->host->imageFingerprint($image->alias(), $image->instanceType());
            } catch (RuntimeException $exception) {
                throw new RuntimeException(
                    "The role image [{$image->alias()}] is missing; run bin/e2e-topology-images build --role={$image->value}.",
                    previous: $exception,
                );
            }
        }

        return $fingerprints;
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
