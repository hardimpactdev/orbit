<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Storage;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\ManagedUserAccount;
use App\Models\Instance;

/**
 * A development checkout lives at ROOT/DIRECTORY/NAME. The directory is the Project slug when
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
            || $checkout->value !== $directory->value.'/'.$instance->name
        ) {
            $this->unsafeInstance($instance);
        }

        return $directory;
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
