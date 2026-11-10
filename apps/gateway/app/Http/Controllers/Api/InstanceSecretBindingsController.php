<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Instances\InstanceSecretBindings;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instances\BindInstanceSecretRequest;
use App\Models\Instance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `instance:secret:bind`, `unbind` and `list` (docs/reference/development-containers.md#secret-bindings). No response holds a value. */
#[RequiresNodeAccess(ServingNode::EnvironmentInstanceOwning)]
final class InstanceSecretBindingsController extends Controller
{
    public function index(Request $request, Instance $instance, InstanceSecretBindings $bindings): JsonResponse
    {
        return response()->json([
            'data' => array_map(InstanceSecretBindings::present(...), $bindings->list($instance)),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    public function store(BindInstanceSecretRequest $request, Instance $instance, string $key, InstanceSecretBindings $bindings): JsonResponse
    {
        $result = $bindings->bind($instance, $key, $request->hosts(), $request->headers(), $request->prefix());

        return response()->json([
            'data' => [...InstanceSecretBindings::present($result['binding']), 'changed' => $result['changed']],
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    public function destroy(Request $request, Instance $instance, string $key, InstanceSecretBindings $bindings): JsonResponse
    {
        return response()->json([
            'data' => InstanceSecretBindings::present($bindings->unbind($instance, $key)),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
