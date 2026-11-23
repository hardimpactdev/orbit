<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Controllers;

use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orbit\Extensions\Conn\Actions\Push\DeleteConnPushDeviceAction;
use Orbit\Extensions\Conn\Actions\Push\UpsertConnPushDeviceAction;
use Orbit\Extensions\Conn\Data\ConnPushDeviceData;
use Orbit\Extensions\Conn\Http\Requests\ShowConnPushDeviceRequest;
use Orbit\Extensions\Conn\Http\Requests\UpsertConnPushDeviceRequest;
use Orbit\Extensions\Conn\Models\ConnPushDevice;

/** Manages the calling Conn Node's iOS push device registrations. */
#[RequiresNodeAccess(ServingNode::Collection)]
final class ConnPushDevicesController extends Controller
{
    public function upsert(UpsertConnPushDeviceRequest $request, UpsertConnPushDeviceAction $action): JsonResponse
    {
        $device = $action->execute($this->caller($request), $request->payload());

        return response()->json([
            'data' => ConnPushDeviceData::fromModel($device)->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    public function show(ShowConnPushDeviceRequest $request): JsonResponse
    {
        $device = ConnPushDevice::query()
            ->where('node_id', $this->caller($request)->id)
            ->where('device_id', $request->deviceId())
            ->first();

        abort_unless($device instanceof ConnPushDevice, 404);

        return response()->json([
            'data' => ConnPushDeviceData::fromModel($device)->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    public function destroy(Request $request, string $device_id, DeleteConnPushDeviceAction $action): JsonResponse
    {
        return response()->json([
            'data' => [
                'device_id' => strtolower($device_id),
                'removed' => $action->execute($this->caller($request), $device_id),
            ],
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    private function caller(Request $request): Node
    {
        $node = $request->user();
        assert($node instanceof Node, description: 'Authenticated peer must be a Node.');

        return $node;
    }
}
