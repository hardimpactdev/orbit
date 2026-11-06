<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Gateway\GatewayControlPlan;
use App\Domain\Nodes\SwampNode;
use App\Models\Node;
use Illuminate\Console\Command;

/** Prints what the Gateway's own Swamp needs on its machine, issues the certificate of the `swamp.orbit` front, or records the hub key. */
final class BootstrapControlPlanCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:bootstrap-control-plan
        {--checkout= : The swamp/ folder of the release on this machine}
        {--certificate : Issue the certificate of swamp.orbit and print its paths}
        {--hub-public-key= : Record the public key of the WireGuard hub the machine made}';

    #[\Override]
    protected $description = 'Print the Swamp control plane plan of this Gateway, or issue the swamp.orbit certificate.';

    public function handle(GatewayControlPlan $plan, GatewayCertificateIssuer $certificates): int
    {
        $hubKey = $this->option('hub-public-key');
        if (is_string($hubKey) && $hubKey !== '') {
            if (strlen((string) base64_decode($hubKey, true)) !== 32) {
                $this->error('The hub public key is not a WireGuard key.');

                return self::FAILURE;
            }
            Node::withoutEvents(static fn (): bool => SwampNode::node()->update(['wireguard_public_key' => $hubKey]));
            $this->line('Hub public key recorded.');

            return self::SUCCESS;
        }
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
