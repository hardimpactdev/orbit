<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use App\Models\Node;
use App\Models\Project;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;
use Orbit\Extensions\Conn\Domain\ConnReservations;
use Orbit\Extensions\Conn\Domain\ConnThreadProjects;
use Orbit\Extensions\Conn\Models\ConnReservation;

/**
 * The name and optional branch a reserved clone takes (docs/reference/conn.md#reserve-a-clone): the same body and checks as a Conn create
 * of that name. Only the device and profile that reserved it finalize it; any other gets 404.
 */
final class FinalizeConnReservationRequest extends CreateConnInstanceRequest
{
    private ?ConnReservation $reservation = null;

    #[\Override]
    public function authorize(ConnThreadProjects $projects): bool
    {
        // The resolver, not user(): its declared type is a class this application does not have.
        $caller = ($this->getUserResolver())();
        if (! $caller instanceof Node) {
            throw new LogicException('An authenticated Node finalizes its reservation.');
        }
        $reservation = ConnReservations::owned($caller, (string) $this->route('reservation'));
        if (! $reservation instanceof ConnReservation) {
            throw (new ModelNotFoundException)->setModel(ConnReservation::class);
        }
        $this->reservation = $reservation;
        // The name checks of a create read the Project from the route.
        $this->route()?->setParameter('project', Project::query()->findOrFail($reservation->project_id));

        return true;
    }

    public function reservation(): ConnReservation
    {
        return $this->reservation ?? throw new LogicException('The request was not authorized.');
    }
}
