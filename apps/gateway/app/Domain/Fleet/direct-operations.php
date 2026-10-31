<?php

declare(strict_types=1);

use App\Domain\Fleet\PeerDnsProjection;

return ['fleet.peer_dns' => [
    'workflow' => 'operation-fleet-peer-dns',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'peer_dns_writer',
    'projection' => PeerDnsProjection::class,
]];
