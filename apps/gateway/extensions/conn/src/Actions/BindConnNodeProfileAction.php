<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Actions;

use App\Models\Node;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\Conn\Domain\ConnProfileEvents;
use Orbit\Extensions\Conn\Models\ConnProfile;
use Orbit\Extensions\Conn\Models\ConnProfileBinding;

/**
 * Binds a Node to one profile. A Node belongs to one profile, so this replaces any earlier binding. Every bind, also to
 * the same profile, gives the binding a new profile event key ([Profile events](/reference/conn#profile-events)).
 */
final readonly class BindConnNodeProfileAction
{
    public function __construct(private ConnProfileEvents $events) {}

    public function execute(Node $node, ConnProfile $profile): ConnProfileBinding
    {
        return DB::transaction(function () use ($node, $profile): ConnProfileBinding {
            $binding = ConnProfileBinding::query()->where('node_id', $node->id)->lockForUpdate()->first()
                ?? new ConnProfileBinding(['node_id' => $node->id]);
            $binding->conn_profile_id = $profile->id;
            $this->events->replace($binding);

            return $binding;
        });
    }
}
