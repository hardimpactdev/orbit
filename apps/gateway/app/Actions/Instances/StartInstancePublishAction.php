<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Data\Instances\InstancePublishInput;
use App\Domain\Operations\OperationKinds;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\GitHub\InstancePublisher;
use App\Models\Instance;
use App\Models\Operation;

final readonly class StartInstancePublishAction
{
    public function __construct(private StartOperationAction $operations, private InstancePublisher $publisher) {}

    public function execute(Instance $instance, InstancePublishInput $input, string $caller, ?string $requestId): Operation
    {
        if (! $instance->placedOnAppDev()) {
            throw new ResourceOperationException('instance_publish.production_forbidden', 'Publish requires a development Instance.');
        }
        $project = $instance->project;
        if ($project->source_access === ProjectSourceAccess::GhCli) {
            throw new ResourceOperationException('instance_publish.source_access_unsupported', 'Publish requires GitHub App repository access.');
        }
        $productionBranch = $project->instances()->with('node.roles')->get()->contains(
            fn (Instance $other): bool => $other->placedOnAppProd() && ($other->deployment_branch ?? $other->branch) === $input->branch,
        );
        if ($input->branch === $project->default_branch || str_starts_with($input->branch, 'task-') || $productionBranch) {
            throw new ResourceOperationException('instance_publish.branch_forbidden', 'Publish cannot write a default, production, or task branch.');
        }
        $this->operations->assertAvailable();
        $this->publisher->check($instance, $input);

        return $this->operations->execute(OperationKinds::InstancePublish, OperationKinds::InstanceTarget, $instance->id, $instance->node_id, $input->payload(), $caller, $requestId);
    }
}
