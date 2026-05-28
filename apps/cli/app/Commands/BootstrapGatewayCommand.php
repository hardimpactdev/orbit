<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\EmitsCanonicalEnvelopes;
use LaravelZero\Framework\Commands\Command;

/**
 * Base for public commands that run before a gateway API exists.
 * Pre-gateway bootstrap helpers can be added later.
 */
abstract class BootstrapGatewayCommand extends Command
{
    use EmitsCanonicalEnvelopes;
}
