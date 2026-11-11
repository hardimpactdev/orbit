<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\PublishAutomaticDeployPolicyAction;
use App\Data\Instances\DevelopmentDeployRetriesData;
use App\Data\Instances\InstanceData;
use App\Domain\Instances\Deployment\AutomaticDeployPolicyWriter;
use App\Domain\Instances\Deployment\DevelopmentDeployRetries;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instances\PublishAutomaticDeployPolicyRequest;
use App\Http\Requests\Instances\ReadDevelopmentDeployRetriesRequest;
use App\Models\Instance;
use Illuminate\Http\JsonResponse;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class InstanceAutomaticDeployPolicyController extends Controller
{
    public function retries(ReadDevelopmentDeployRetriesRequest $request, Instance $instance, DevelopmentDeployRetries $retries): JsonResponse
    {
        AutomaticDeployPolicyWriter::Swamp->assertSwamp($instance, development: true);

        return response()->json(['data' => DevelopmentDeployRetriesData::from($retries->read($instance, $request->commits()))->toArray(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    public function update(PublishAutomaticDeployPolicyRequest $request, Instance $instance, PublishAutomaticDeployPolicyAction $action): JsonResponse
    {
        return response()->json(['data' => InstanceData::fromModel($action->execute($instance, $request->payload()))->toArray(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }
}
