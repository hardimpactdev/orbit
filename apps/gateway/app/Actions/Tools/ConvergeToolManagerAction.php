<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationFamilySpec;
use App\Domain\Tools\ToolManagerIntent;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerRegistry;
use App\Models\Node;
use App\Models\Operation;
use LogicException;

final readonly class ConvergeToolManagerAction
{
    public function __construct(private StartOperationAction $operations, private ToolManagerIntent $intent, private ToolManagerRegistry $managers) {}

    public function execute(Node $node, ToolManagerName $manager, bool $imageProvidesManager = false): Operation
    {
        OperationFamilySpec::assertAvailable('tool.manager_converge');

        $driver = $this->managers->find($manager->value);
        if ($driver === null || ! $driver->supportsNode($node)) {
            throw new LogicException('The requested Tool manager does not support this Node.');
        }

        return $this->operations->execute('tool.manager_converge', 'node_tools', $node->id, $node->id, [
            'node_id' => $node->id, 'manager' => $manager->value, 'completion_variant' => $manager->value.':version', 'platform' => $node->platform,
            'architecture' => $node->architecture, ...$this->intent->input($node, $manager, $imageProvidesManager),
        ], 'system', null);
    }
}
