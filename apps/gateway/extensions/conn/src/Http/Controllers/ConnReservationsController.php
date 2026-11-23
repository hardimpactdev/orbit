<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Controllers;

use App\Data\Operations\OperationData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orbit\Extensions\Conn\Data\ConnReservationData;
use Orbit\Extensions\Conn\Domain\ConnReservations;
use Orbit\Extensions\Conn\Http\Requests\FinalizeConnReservationRequest;
use Orbit\Extensions\Conn\Http\Requests\ReserveConnInstanceRequest;
use Orbit\Extensions\Conn\Models\ConnReservation;

/** Reserved clones of a Conn device (docs/reference/conn.md#reserve-a-clone). Only the device and profile that reserved one read, release or finalize it. */
#[RequiresNodeAccess(ServingNode::Collection)]
final class ConnReservationsController
{
    public function store(ReserveConnInstanceRequest $request, Project $project, ConnReservations $reservations): JsonResponse
    {
        [$reservation, $created] = $reservations->reserve($this->caller($request), $project, $request->default(), $request->attributes->getString('orbit.request_id'));

        return $this->answer($request, ConnReservationData::fromModel($reservation)->toArray(), $created ? 202 : 200);
    }

    public function show(Request $request, string $reservation, ConnReservations $reservations): JsonResponse
    {
        $caller = $this->caller($request);
        $touched = $reservations->touch($this->owned($caller, $reservation));
        $pairing = $reservations->claimPairing($touched, $caller);

        return $this->answer($request, ConnReservationData::fromModel($touched, $pairing)->toArray())->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request, string $reservation, ConnReservations $reservations): JsonResponse
    {
        $owned = $this->owned($this->caller($request), $reservation);
        $reservations->release($owned, ConnReservation::Released);

        return $this->answer($request, ConnReservationData::fromModel($owned->refresh())->toArray(), 202);
    }

    #[StartsOperation('instance.finalize', requiresKey: true, bindCaller: true)]
    public function finalize(FinalizeConnReservationRequest $request, ConnReservations $reservations): JsonResponse
    {
        $operation = $reservations->finalize($request->reservation(), $this->caller($request), $request->name(), $request->branchSelection(),
            $request->attributes->getString('orbit.request_id'));

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], 202);
    }

    private function owned(Node $caller, string $id): ConnReservation
    {
        $reservation = ConnReservations::owned($caller, $id);
        if (! $reservation instanceof ConnReservation) {
            throw (new ModelNotFoundException)->setModel(ConnReservation::class);
        }

        return $reservation;
    }

    /** @param  array<string, mixed>  $data */
    private function answer(Request $request, array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], $status);
    }

    private function caller(Request $request): Node
    {
        $caller = $request->user();
        assert($caller instanceof Node, description: 'Authenticated peer must be a Node.');

        return $caller;
    }
}
