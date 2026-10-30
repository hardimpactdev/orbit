<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

final readonly class StartNodePhpPackagesAction
{
    public function __construct(private StartOperationAction $operations, private ManagedNodeEligibility $eligibility, private RemotePhpPackageManager $packages, private OperationIdempotency $identity) {}

    /** @param array<array-key, mixed> $versions Version selection from a recorded parent intent. */
    public function execute(Node $node, array $versions): Operation
    {
        if ($this->identity->parent === null || ! in_array($this->identity->parent->kind, ['instance.deploy', 'instance.rollback', 'instance.prepare_php_runtime'], true)
            || $this->identity->parent->node_id !== $node->id || $this->identity->kind !== 'node.prepare_php_packages') {
            throw new ResourceOperationException('operation.child_forbidden', 'PHP package preparation requires its owning deployment or Route runtime child admission.', 422);
        }
        if ($node->platform !== 'linux' || ! $this->eligibility->isManagedForObservation($node)
            || ! in_array($node->status, [LifecycleStatus::Active, LifecycleStatus::Failed], true)) {
            throw new ResourceOperationException('app-prod.php_package_source_unavailable', 'PHP package preparation requires a managed Linux Node.', 422);
        }
        if (! array_is_list($versions) || $versions === [] || count($versions) > 8
            || array_any($versions, static fn ($version): bool => ! is_string($version) || preg_match('/\A[0-9]+\.[0-9]+\z/D', $version) !== 1)
            || count(array_unique($versions, SORT_REGULAR)) !== count($versions)) {
            throw new ResourceOperationException('node.php_packages_invalid', 'Select one to eight distinct PHP versions.', 422);
        }

        return $this->operations->execute('node.prepare_php_packages', 'node', $node->id, $node->id,
            ['versions' => $versions, 'profile' => 'app-prod', '_php_packages' => $this->packages->packagesOnlyInputForAppProd($node->load('roles'), ValidatedData::stringList($versions)),
                '_php_packages_revision' => $this->packages->packagesOnlyRecipeRevision(), '_php_packages_deadline' => now()->addSeconds(120)->getTimestamp()], 'system', null);
    }
}
