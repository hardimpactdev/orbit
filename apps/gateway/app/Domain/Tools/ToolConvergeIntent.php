<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Actions\Tools\StartToolOperationAction;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Tool;
use App\Support\ValidatedData;

final readonly class ToolConvergeIntent
{
    public function __construct(
        private StartToolOperationAction $tools,
        private ToolPolicy $policy,
        private ToolDefinitionResolver $definitions,
        private ToolPackageProtection $protection,
    ) {}

    /** @return list<array<string, mixed>> */
    public function capture(Node $node): array
    {
        $this->assertIdle($node->id, direct: false);
        $active = $this->policy->active();
        $packages = [];
        $repositories = [];
        foreach (Tool::query()->where('node_id', $node->id)->with(['manager', 'aptRepository'])->orderBy('id')->get() as $tool) {
            $manager = $this->tools->manager($node, $tool->manager->name);
            $protected = $this->protection->forNode($node, $manager->name());
            if (in_array($tool->package, $protected, true) || ($manager instanceof ReservesRolePackages && $manager->roleOwns($tool->package))) {
                continue;
            }
            $definition = $this->definitions->resolve('update', $manager, $tool->package, $tool, null, null, null, $active);
            $definition = new ToolDefinition($definition->version, $definition->constraint, $definition->repository ?? $tool->aptRepository?->definition);
            if ($definition->repository !== null) {
                $name = ValidatedData::string($definition->repository['name']);
                if (isset($repositories[$name]) && $repositories[$name]['definition'] != $definition->repository) {
                    throw new ResourceOperationException('tool.repository_conflict', 'A Node can admit only one definition of a signed apt repository.', 409);
                }
                $repositories[$name]['definition'] = $definition->repository;
                $repositories[$name]['packages'][] = $tool->package;
            }
            $packages[] = new AdmittedToolPackage($node, $manager->name(), $tool->package, $definition, $tool, $tool->manager, $protected)->toArray();
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

    public function assertIdle(int $nodeId, bool $direct): void
    {
        $open = Operation::query()->where('node_id', $nodeId)->whereIn('status', OperationStatus::openValues());
        if ($direct) {
            $open->where(static fn ($query) => $query->where('kind', 'tool.converge')
                ->orWhere(static fn ($node) => $node->where('kind', 'node.converge')->where('input->_converge->tool_scope', true)));
        } else {
            $open->where('kind', 'like', 'tool.%');
        }
        if ($open->exists()) {
            throw new ResourceOperationException('operation.target_busy', 'Another Tool Operation owns this Node.', 409);
        }
    }
}
