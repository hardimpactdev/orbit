<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Data\Instances\AutomaticDeployReceiptData;
use App\Data\Instances\InstanceDeploymentData;
use App\Data\Operations\OperationData;
use App\Domain\Operations\OperationRequestFingerprint;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\Route;
use LogicException;

final readonly class ReadAutomaticDeployReceiptAction
{
    public function __construct(private OperationRequestFingerprint $fingerprints) {}

    public function execute(Instance $instance, Node $caller, string $key, string $commit): AutomaticDeployReceiptData
    {
        $route = Route::getRoutes()->getByName('instance:deploy:automatic') ?? throw new LogicException('The automatic admission route is missing.');
        $start = StartsOperation::forRoute($route) ?? throw new LogicException('Automatic admission must declare StartsOperation.');
        $operation = Operation::query()->where('kind', $start->kind)->where('idempotency_key', $key)->first();
        if ($operation === null) {
            return new AutomaticDeployReceiptData($key, false, null, null);
        }
        $fingerprint = $this->fingerprints->make($caller, $route->getName(), ['instance' => $instance], json_encode(['commit' => $commit], JSON_THROW_ON_ERROR), $start);
        if ($operation->idempotency_fingerprint !== $fingerprint) {
            throw new ResourceOperationException('operation.idempotency_conflict', 'The request key belongs to another caller, command, Instance, or input.', 409);
        }
        $id = $operation->result['deployment_id'] ?? null;
        $deployment = is_int($id) ? InstanceDeployment::query()->where('instance_id', $instance->id)->find($id) : null;

        return new AutomaticDeployReceiptData($key, true, OperationData::fromModel($operation), $deployment === null ? null : InstanceDeploymentData::fromModel($deployment));
    }
}
