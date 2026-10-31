<?php

declare(strict_types=1);
use App\Domain\DatabaseServers\DatabaseOwnedReady;
use App\Domain\DatabaseServers\DatabaseProven;

return ['database.proven' => DatabaseProven::class, 'database.owned-ready' => DatabaseOwnedReady::class];
