<?php

declare(strict_types=1);

use App\Domain\Routes\PrepareCaddyCommand;
use App\Domain\Routes\PreparePrivateDnsCommand;

return [
    'route.offline-residue' => \App\Domain\Routes\OfflineResidueCommand::class,
    'route.instance-runtime' => \App\Domain\Routes\InstanceRuntimeCommand::class,'route.caddy-prepare' => PrepareCaddyCommand::class, 'route.private-dns-prepare' => PreparePrivateDnsCommand::class];
