<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Projects\ProjectUpdateSourceMutator;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use Closure;

final class FakeProjectUpdateSourceMutator implements ProjectUpdateSourceMutator
{
    /** @var list<string> */
    public array $originMutations = [];

    /** @var list<string> */
    public array $originRestores = [];

    /** @var list<int> */
    public array $switchedInstances = [];

    /** @var list<int> */
    public array $restoredBranches = [];

    public bool $refuseRepositoryPreflight = false;

    public bool $failOriginChange = false;

    /** The checkout, counted from 1, whose origin change fails after the earlier ones changed. */
    public ?int $failOriginChangeAt = null;

    /** Restores that fail before the next one succeeds. */
    public int $failOriginRestores = 0;

    public bool $refuseDefaultBranchPreflight = false;

    /**
     * @param  list<Instance>  $checkouts
     */
    public function preflightRepository(array $checkouts, string $currentUrl, string $proposedUrl): void
    {
        if ($this->refuseRepositoryPreflight) {
            throw new ResourceOperationException(
                errorCode: 'project.repository_preflight_failed',
                message: 'An affected source cannot switch to the proposed repository.',
                status: 409,
            );
        }
    }

    public function changeOrigins(array $checkouts, string $previousUrl, string $newUrl, array $evidence, ?Closure $record = null): array
    {
        if ($this->failOriginChange && $evidence === []) {
            throw new ResourceOperationException(
                errorCode: 'project.repository_origin_failed',
                message: 'Changing a checkout origin failed.',
                status: 409,
            );
        }

        $byPath = [];

        foreach ($evidence as $row) {
            $byPath[$row['path']] = $row;
        }

        $position = 0;
        foreach ($checkouts as $checkout) {
            $path = rtrim($checkout->checkout_path, '/');
            $position++;

            if (($byPath[$path]['mutated'] ?? false) === true) {
                continue;
            }

            $byPath[$path] = ['instance_id' => $checkout->id, 'path' => $path, 'previous_url' => $previousUrl, 'current_url' => $newUrl, 'mutated' => false, 'attempted' => true];
            if ($record !== null) {
                $record(array_values($byPath));
            }
            if ($this->failOriginChangeAt === $position) {
                throw new ResourceOperationException(
                    errorCode: 'project.repository_origin_failed',
                    message: 'Changing a checkout origin failed.',
                    status: 409,
                );
            }

            $this->originMutations[] = $path;
            $byPath[$path] = [...$byPath[$path], 'mutated' => true];
            if ($record !== null) {
                $record(array_values($byPath));
            }
        }

        return array_values($byPath);
    }

    public function restoreOrigins(array $mutations): void
    {
        if ($this->failOriginRestores > 0) {
            $this->failOriginRestores--;

            throw new ResourceOperationException(
                errorCode: 'project.repository_origin_failed',
                message: 'Restoring a checkout origin failed.',
                status: 409,
            );
        }

        foreach ($mutations as $mutation) {
            if ($mutation['mutated'] !== true && ($mutation['attempted'] ?? false) !== true) {
                continue;
            }

            $this->originRestores[] = $mutation['path'];
        }
    }

    public function preflightDefaultBranch(Instance $instance, string $newBranch): void
    {
        if ($this->refuseDefaultBranchPreflight) {
            throw new ResourceOperationException(
                errorCode: 'project.source_switch_failed',
                message: 'An affected source cannot switch to the proposed default branch.',
                status: 409,
            );
        }
    }

    public function switchDefaultBranch(Instance $instance, string $newBranch): void
    {
        $this->switchedInstances[] = $instance->id;
    }

    public function restoreDefaultBranch(Instance $instance, string $previousBranch): void
    {
        $this->restoredBranches[] = $instance->id;
    }
}
