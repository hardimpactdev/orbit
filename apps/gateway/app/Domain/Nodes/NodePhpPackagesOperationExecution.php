<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Log;

final readonly class NodePhpPackagesOperationExecution implements OperationFamilyExecution
{
    public function __construct(private RemotePhpPackageManager $packages) {}

    public function context(Operation $operation): array
    {
        return ['descriptors' => [['key' => 'php-packages', 'step' => 'php-packages', 'transport' => 'agent',
            'name' => 'php.install-build', 'input' => $this->input($operation, []), 'required' => true, 'resumable' => false]]];
    }

    public function input(Operation $operation, array $run): array
    {
        return ValidatedData::object($operation->input['_php_packages']);
    }

    public function beforeRun(Operation $operation, array $run): void
    {
        $deadline = $operation->input['_php_packages_deadline'] ?? null;
        $revision = $operation->input['_php_packages_revision'] ?? null;
        if (! is_int($deadline) || now()->getTimestamp() >= $deadline
            || ! is_string($revision) || ! hash_equals($this->packages->packagesOnlyRecipeRevision(), $revision)) {
            throw new ResourceOperationException('app-prod.php_package_source_unavailable', 'The admitted PHP recipe expired or changed before dispatch.', 409);
        }
    }

    public function received(Operation $operation, array $run, array $receipt): array
    {
        if (isset($receipt['error_code'])) {
            return $receipt;
        }
        $data = $receipt['data'] ?? null;
        if (! is_array($data)) {
            return [...$receipt, 'error_code' => 'app-prod.php_install_failed'];
        }
        foreach (['stale_masters', 'deferred'] as $name) {
            $list = $data[$name] ?? [];
            if (! is_array($list) || ! array_is_list($list) || count($list) > 256 || array_any($list, static fn ($unit): bool => ! is_string($unit))) {
                return [...$receipt, 'error_code' => 'app-prod.php_install_failed'];
            }
            $receipt[$name] = $list;
            if ($list !== []) {
                Log::warning($name === 'stale_masters'
                    ? 'Dedicated PHP-FPM masters may still use the previous PHP build or settings.'
                    : 'PHP-FPM masters failed configuration validation; their restart or reload was deferred.',
                    ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'masters' => $list]);
            }
        }

        return $receipt;
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void {}

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }
}
