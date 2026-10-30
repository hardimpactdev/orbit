<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

final readonly class RouteKitAvailability
{
    public static function kind(string $kind): void
    {
        if (! array_key_exists($kind, OperationKinds::definitions())) {
            throw new ResourceOperationException('operation.kind_unavailable', 'This Route contract is not enabled by the shared kit.', 409);
        }
    }

    /** Closed declarations include only kinds supplied by their owning family.
     * @param  list<string>  $kinds
     * @return list<string>
     */
    public static function registeredChildren(array $kinds): array
    {
        $registered = [];
        foreach (['Certificates', 'Instances', 'Nodes', 'Metrics'] as $family) {
            $file = __DIR__.'/../'.$family.'/operation-kinds.php';
            if (is_file($file)) {
                $registered = [...$registered, ...array_keys(require $file)];
            }
        }

        return array_values(array_filter($kinds, static fn (string $kind): bool => str_starts_with($kind, 'route.') || in_array($kind, $registered, true)));
    }

    /** All forward and rollback dependencies are proven before admission commits.
     * @param  array<string, mixed>  $plan
     */
    public static function plan(Operation $operation, array $plan): void
    {
        $registry = app(OperationKinds::class);
        $definition = $registry->definition($operation->kind);
        $declared = is_array($definition) ? array_column($definition['children'] ?? [], 'kind') : [];
        foreach (ValidatedData::object($plan['stages'] ?? []) as $children) {
            foreach (RouteExecutionProof::objects($children) as $child) {
                $kind = ValidatedData::string($child['kind']);
                if ($registry->definition($kind) === null || ! in_array($kind, $declared, true)) {
                    throw new ResourceOperationException('operation.kind_unavailable', "The Route plan requires the registered child [{$kind}].", 409);
                }
            }
        }
    }
}
