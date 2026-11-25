<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Tool;
use App\Support\ValidatedData;
use Illuminate\Database\Eloquent\Builder;

final readonly class ToolConvergeIntent
{
    public function __construct(
        private ToolManagerRegistry $managers,
        private ToolPolicy $policy,
        private ToolDefinitionResolver $definitions,
        private ToolPackageProtection $protection,
    ) {}

    /** @return list<array<string, mixed>> */
    public function capture(Node $node, ?int $owner = null): array
    {
        $this->assertIdle($node->id, $owner);
        $active = $this->policy->active();
        $packages = [];
        $repositories = [];
        foreach (Tool::query()->where('node_id', $node->id)->with(['manager', 'aptRepository'])->orderBy('id')->get() as $tool) {
            $manager = $this->managers->find($tool->manager->name);
            if ($manager === null) {
                continue;
            }
            $protected = $this->protection->forNode($node, $manager->name());
            if (in_array($tool->package, $protected, true) || ($manager instanceof ReservesRolePackages && $manager->roleOwns($tool->package))) {
                continue;
            }
            $definition = $this->definitions->resolve('update', $manager, $tool->package, $tool, null, null, null, $active);
            if ($definition->repository !== null) {
                $name = ValidatedData::string($definition->repository['name']);
                if (isset($repositories[$name]) && ! ToolDefinition::sameRepository($repositories[$name]['definition'], $definition->repository)) {
                    throw new ResourceOperationException('tool.repository_conflict', 'A Node can admit only one definition of a signed apt repository.', 409);
                }
                $repositories[$name]['definition'] = $definition->repository;
                $repositories[$name]['packages'][] = $tool->package;
            }
            $package = new AdmittedToolPackage($node, $manager->name(), $tool->package, $definition, $tool, $tool->manager, $protected)->toArray();
            if (! $manager->supportsNode($node)) {
                $package['manager_unavailable'] = true;
            }
            $packages[] = $package;
        }

        return array_map(static function (array $package) use ($repositories): array {
            if (is_array($package['repository'])) {
                $name = ValidatedData::string($package['repository']['name']);
                $members = $repositories[$name]['packages'];
                sort($members, SORT_STRING);
                $package['repository_packages'] = $members;
            }

            return $package;
        }, $packages);
    }

    /** @return Builder<Operation> */
    public static function writers(): Builder
    {
        return Operation::query()->where(static fn ($query) => $query->whereIn('status', OperationStatus::openValues())->orWhereNotNull('lock_key'))
            ->where(static fn ($query) => $query->where('kind', 'like', 'tool.%')
                ->orWhere(static fn ($node) => $node->where('kind', 'node.converge')
                    ->where(static fn ($intent) => $intent->where('input->_converge->tool_scope', true)
                        ->orWhereJsonLength('input->_converge->intent->tools', '>', 0))));
    }

    public function assertIdle(int $nodeId, ?int $owner = null): void
    {
        if (self::writers()->where('node_id', $nodeId)->when($owner !== null, static fn ($query) => $query->whereKeyNot($owner))->exists()) {
            throw new ResourceOperationException('operation.target_busy', 'Another Tool Operation owns this Node.', 409);
        }
    }
}
