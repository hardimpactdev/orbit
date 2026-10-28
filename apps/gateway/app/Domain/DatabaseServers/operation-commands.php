<?php

declare(strict_types=1);
use App\Domain\DatabaseServers\DatabaseOwnedReady;
use App\Domain\DatabaseServers\DatabaseProven;
use App\Domain\DatabaseServers\DatabaseServerAbsent;

return ['database.proven' => DatabaseProven::class, 'database.owned-ready' => DatabaseOwnedReady::class, 'database.server-absent' => DatabaseServerAbsent::class];
