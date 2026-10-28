<?php

declare(strict_types=1);

use App\Domain\Routes\InstanceRuntimeCommand;
use App\Domain\Routes\OfflineResidueCommand;
use App\Domain\Routes\PrepareCaddyCommand;
use App\Domain\Routes\PreparePrivateDnsCommand;

return [
    'route.offline-residue' => OfflineResidueCommand::class,
    'route.instance-runtime' => InstanceRuntimeCommand::class, 'route.caddy-prepare' => PrepareCaddyCommand::class, 'route.private-dns-prepare' => PreparePrivateDnsCommand::class];
