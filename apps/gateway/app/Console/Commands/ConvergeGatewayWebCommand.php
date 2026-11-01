<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Gateway\ConvergeGatewayWebAction;
use App\Domain\Nodes\NodeProvisioningException;
use Illuminate\Console\Command;

final class ConvergeGatewayWebCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:gateway-web';

    #[\Override]
    protected $description = 'Converge the Gateway web site.';

    public function handle(ConvergeGatewayWebAction $action): int
    {
        try {
            $action->execute();
        } catch (NodeProvisioningException $exception) {
            $this->error(
                "Gateway web convergence failed at step [{$exception->step}] with error [{$exception->errorCode}].",
            );

            return self::FAILURE;
        }
    }
}
