<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Models\Node;
use App\Models\Operation;

/** Captures the recipe once; a workflow can never select package pins or installer policy. */
final readonly class PrepareNodePhpPackagesCommand implements OperationGatewayCommand
{
    public function __construct(private RemotePhpPackageManager $packages, private ManagedNodeEligibility $eligibility, private OperationStore $operations) {}

    /** @return array<string, mixed> */
    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['versions', 'profile'],
            'properties' => ['versions' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 8, 'uniqueItems' => true,
                'items' => ['type' => 'string', 'pattern' => '^[0-9]+\.[0-9]+$']],
                'profile' => ['const' => 'app-prod']]];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        if (($operation->state['node_php_packages_prepared'] ?? false) === true) {
            return ['prepared' => true];
        }
        $node = Node::query()->findOrFail($operation->node_id)->load('roles');
        if (! $this->eligibility->allows($node)) {
            throw new ResourceOperationException('node.php_packages_unsupported', 'PHP package preparation requires an active managed Linux Node.', 422);
        }
        $versions = $input['versions'] ?? null;
        if (($input['profile'] ?? null) !== 'app-prod' || ! is_array($versions) || ! array_is_list($versions)
            || $versions === [] || count($versions) > 8 || count(array_unique($versions, SORT_REGULAR)) !== count($versions)
            || array_any($versions, static fn ($version): bool => ! is_string($version) || preg_match('/\A[0-9]+\.[0-9]+\z/D', $version) !== 1)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The PHP prerequisite input is invalid.', 422);
        }
        try {
            $snapshot = $this->packages->packagesOnlyInputForAppProd($node, $versions);
        } catch (RuntimeConvergenceException $exception) {
            return ['error_code' => $exception->errorCode];
        }
        $this->operations->change($operation, [
            'input' => [...$operation->input, '_php_packages' => $snapshot],
            'state' => [...($operation->state ?? []), 'node_php_packages_prepared' => true],
        ]);

        return ['prepared' => true];
    }
}
