<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Storage;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\TaskVms\TaskVmPlacement;
use App\Models\Instance;
use App\Models\Node;
use App\Models\TaskVm;

/**
 * A development checkout lives at ROOT/DIRECTORY/NAME, or at ROOT/DIRECTORY/workspace for the one workspace of a task
 * VM ({@see TaskVmPlacement::WorkspaceCheckout}). The directory is the Project slug when
 * the checkout was created; a later slug change keeps the checkout, so the boundary reads the
 * directory from the stored path and never from the current slug.
 */
final readonly class CheckoutRemovalBoundary
{
    public function __construct(
        private ProtectedPathCatalog $catalog,
    ) {}

    public function instanceRoot(Instance $instance, ManagedUserAccount $account): StoragePath
    {
        $root = $this->instanceGroupingDirectory($instance)->parent();

        if (! $root instanceof StoragePath || $this->catalog->isProtected($root, $account)) {
            $this->unsafeInstance($instance);
        }

        return $root;
    }

    public function instanceGroupingDirectory(Instance $instance): StoragePath
    {
        $checkout = StoragePath::tryParse($instance->checkout_path);
        $directory = $checkout?->parent();

        if (
            ! $directory instanceof StoragePath
            || ($checkout->value !== $directory->value.'/'.$instance->name && ! $this->isTaskVmWorkspace($instance, $checkout, $directory))
        ) {
            $this->unsafeInstance($instance);
        }

        return $directory;
    }

    private function isTaskVmWorkspace(Instance $instance, StoragePath $checkout, StoragePath $directory): bool
    {
        return $checkout->value === $directory->value.'/'.TaskVmPlacement::WorkspaceCheckout
            && $instance->node instanceof Node && TaskVmPlacement::forNode($instance->node) instanceof TaskVm;
    }

    private function unsafeInstance(Instance $instance): never
    {
        throw new RuntimeConvergenceException(
            step: 'app-instance-source-path',
            errorCode: 'instance.checkout_path_unsafe',
            message: "Instance [{$instance->name}] has an unsafe checkout path.",
        );
    }
}
