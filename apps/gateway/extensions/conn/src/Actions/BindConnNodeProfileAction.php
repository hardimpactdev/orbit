<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Actions;

use App\Models\Node;
use Orbit\Extensions\Conn\Models\ConnProfile;
use Orbit\Extensions\Conn\Models\ConnProfileBinding;

/** Binds a Node to one profile. A Node belongs to one profile, so this replaces any earlier binding. */
final readonly class BindConnNodeProfileAction
{
    public function execute(Node $node, ConnProfile $profile): ConnProfileBinding
    {
        return ConnProfileBinding::query()->updateOrCreate(
            ['node_id' => $node->id],
            ['conn_profile_id' => $profile->id],
        );
    }
}
