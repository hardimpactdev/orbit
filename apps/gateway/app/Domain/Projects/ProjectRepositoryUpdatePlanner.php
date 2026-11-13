<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use Illuminate\Database\Eloquent\Collection;

final readonly class ProjectRepositoryUpdatePlanner
{
    /**
     * @param  Collection<int, Instance>  $instances
     *                                                A container Instance keeps no `origin`: its workspace is filled from the Node's mirror, which every run fetches with the
     *                                                Project's URL. So it is no checkout here; the update checks the proposed repository for it on the Gateway.
     * @return array{checkouts: list<Instance>, worktrees: list<Instance>, production: list<Instance>, containers: list<Instance>}
     */
    public function inventory(Collection $instances): array
    {
        $checkouts = [];
        $worktrees = [];
        $production = [];
        $containers = [];

        foreach ($instances->loadMissing('runtime') as $instance) {
            if ($instance->placedOnAppProd()) {
                $production[] = $instance;

                continue;
            }

            if ($instance->runtime !== null) {
                $containers[] = $instance;

                continue;
            }

            if ($instance->source_layout === InstanceSourceLayout::Worktree->value) {
                $worktrees[] = $instance;

                continue;
            }

            if ($instance->source_layout === InstanceSourceLayout::Checkout->value) {
                $checkouts[] = $instance;
            }
        }

        return [
            'checkouts' => $checkouts,
            'worktrees' => $worktrees,
            'production' => $production,
            'containers' => $containers,
        ];
    }

    /**
     * @param  list<Instance>  $checkouts
     * @param  list<Instance>  $worktrees
     */
    public function assertWorktreesOwned(array $checkouts, array $worktrees): void
    {
        foreach ($worktrees as $worktree) {
            if ($this->ownedCheckout($checkouts, $worktree) instanceof Instance) {
                continue;
            }

            throw new ResourceOperationException(
                errorCode: 'project.repository_unowned_common',
                message: 'A worktree uses a common repository that no Orbit-owned checkout owns.',
                status: 409,
            );
        }
    }

    /**
     * @param  list<Instance>  $checkouts
     * @return list<string>
     */
    public function uniqueCheckoutPaths(array $checkouts): array
    {
        $paths = [];

        foreach ($checkouts as $checkout) {
            $paths[rtrim($checkout->checkout_path, '/')] = true;
        }

        return array_keys($paths);
    }

    /**
     * @param  list<Instance>  $checkouts
     */
    public function ownedCheckout(array $checkouts, Instance $worktree): ?Instance
    {
        foreach ($checkouts as $checkout) {
            if ($this->ownsCommonRepository($checkout, $worktree)) {
                return $checkout;
            }
        }

        return null;
    }

    private function ownsCommonRepository(Instance $checkout, Instance $worktree): bool
    {
        if ($checkout->node_id !== $worktree->node_id) {
            return false;
        }

        // A registered worktree records its common repository. A worktree that Orbit created from a seed
        // checkout records only that seed, whose repository is the common one.
        $common = $worktree->registration_common_repository_path ?? $worktree->seed_repository;

        if (! is_string($common) || $common === '') {
            return false;
        }

        $checkoutPath = rtrim($checkout->checkout_path, '/');
        $common = rtrim($common, '/');

        if ($checkoutPath === $common) {
            return true;
        }

        if (str_ends_with($common, '/.git') && $checkoutPath === substr($common, 0, -5)) {
            return true;
        }

        $checkoutCommon = $checkout->registration_common_repository_path;

        return is_string($checkoutCommon) && rtrim($checkoutCommon, '/') === $common;
    }
}
