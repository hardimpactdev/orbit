<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Gateway\GatewayControlPlan;
use App\Domain\Nodes\SwampNode;
use Illuminate\Console\Command;

/** Prints what the Gateway's own Swamp needs on its machine, or issues the certificate of the `swamp.orbit` front. */
final class BootstrapControlPlanCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:bootstrap-control-plan
        {--checkout= : The swamp/ folder of the release on this machine}
        {--certificate : Issue the certificate of swamp.orbit and print its paths}';

    #[\Override]
    protected $description = 'Print the Swamp control plane plan of this Gateway, or issue the swamp.orbit certificate.';

    public function handle(GatewayControlPlan $plan, GatewayCertificateIssuer $certificates): int
    {
        if ($this->option('certificate')) {
            $node = SwampNode::node();
            $paths = $certificates->issue('swamp.orbit', (string) $node->wireguard_ip);
            $this->line(json_encode(['certificate' => $paths->certificatePath, 'key' => $paths->privateKeyPath], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $checkout = $this->option('checkout');
        if (! is_string($checkout) || $checkout === '') {
            $this->error('Pass --checkout.');

            return self::FAILURE;
        }
        $this->line(json_encode($plan->make($checkout), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
