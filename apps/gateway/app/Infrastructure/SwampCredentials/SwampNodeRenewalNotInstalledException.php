<?php

declare(strict_types=1);

namespace App\Infrastructure\SwampCredentials;

use RuntimeException;

/** The Swamp Node has the token entrypoint but not its renewal unit yet: only the later swamp role converge installs that unit. */
final class SwampNodeRenewalNotInstalledException extends RuntimeException
{
    public const string Code = 'gateway.swamp_node_renewal_not_installed';
}
