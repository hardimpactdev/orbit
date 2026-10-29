<?php

declare(strict_types=1);

use App\Domain\Routes\PrepareCaddyCommand;
use App\Domain\Routes\PreparePrivateDnsCommand;

return ['route.caddy-prepare' => PrepareCaddyCommand::class, 'route.private-dns-prepare' => PreparePrivateDnsCommand::class];
