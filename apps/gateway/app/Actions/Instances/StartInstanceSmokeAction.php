<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\CancelOperationAction;
use App\Actions\Operations\StartOperationAction;
use App\Data\Instances\InstanceSmokeRequestData;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\GitHub\GitHubActionsReader;
use App\Infrastructure\GitHub\GitHubActionsUnavailable;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;

final readonly class StartInstanceSmokeAction
{
    public function __construct(private StartOperationAction $operations, private ReadInstanceSmokeContextAction $context,
        private GitHubActionsReader $github, private CancelOperationAction $cancel) {}

    public function execute(Instance $instance, Node $caller, InstanceSmokeRequestData $data, string $requestId): Operation
    {
        $this->assertSelection($instance, $data);
        $repository = GitHubRepository::fromOrigin($instance->project
            ->repository_url) ?? throw new ResourceOperationException('instance.smoke_invalid',
                'Smoke requires a GitHub Project repository.');
        try {
            $this->github->dispatchToken($repository);
        } catch (GitHubActionsUnavailable $exception) {
            throw $exception->status !== null && ! $exception->retryable()
                ? new ResourceOperationException('instance.smoke_invalid',
                    'Install the Gateway App on the repository with Actions write.')
                : new ResourceOperationException('instance.smoke_github_unavailable',
                    'GitHub is unavailable. Try admission again.', 503);
        }
        $this->operations->assertAvailable();
        $holder = Operation::query()->where('lock_key', 'instance_smoke:'.$instance->id)
            ->where('kind', OperationKinds::InstanceSmoke)->first();
        if ($holder !== null && ($holder->input['deployment_id'] ?? null) !== $data->deploymentId
            && ! $holder->status->terminal()) {
            $this->cancel->execute($holder);
        }

        return $this->operations->execute(OperationKinds::InstanceSmoke, 'instance_smoke', $instance->id, $instance->node_id,
            [...$data->toArray(), 'caller_node_id' => $caller->id, 'repository_url' => $instance->project->repository_url,
                'route_id' => $instance->routes()->where('domain', substr($data->baseUrl, 8))->value('routes.id')], 'swamp',
            $requestId);
    }

    public function assertSelection(Instance $instance, InstanceSmokeRequestData $data): void
    {
        $context = $this->context->execute($instance);
        if (! $context->production || ! in_array($data->baseUrl, $context->baseUrls, true)) {
            throw new ResourceOperationException('instance.smoke_invalid',
                'Smoke requires an active production Instance and its active Project Route.');
        }
        $selected = $context->selected;
        if ($selected === null || $selected->deploymentId !== $data->deploymentId || $selected->commit !== $data->commit
            || $selected->release !== $data->release) {
            throw new ResourceOperationException('instance.smoke_selection_changed',
                'The selected successful production deployment changed.', 409);
        }
    }
}
