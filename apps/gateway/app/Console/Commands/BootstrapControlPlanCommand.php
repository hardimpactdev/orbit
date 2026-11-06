<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Gateway\GatewayControlPlan;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\SwampNode;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\Caddy\Build\CaddySiteRoles;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Route;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/** Prints what the Gateway's own Swamp needs on its machine, issues the certificate of the `swamp.orbit` front or of the Gateway site, or records the hub key. */
final class BootstrapControlPlanCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:bootstrap-control-plan
        {--checkout= : The swamp/ folder of the release on this machine}
        {--certificate= : Issue the certificate of the swamp or gateway site and print its paths}
        {--hub-public-key= : Record the public key of the WireGuard hub the machine made}
        {--serving : Record that this machine serves Swamp: the swamp role is active}
        {--agent-secret-hash= : Record the SHA-256 verifier of the agent key the machine made}';

    #[\Override]
    protected $description = 'Print the Swamp control plane plan of this Gateway, or issue the certificate of one of its sites.';

    public function handle(GatewayControlPlan $plan, GatewayCertificateIssuer $certificates, VpnSettings $vpn): int
    {
        $hubKey = $this->option('hub-public-key');
        if (is_string($hubKey) && $hubKey !== '') {
            if (strlen((string) base64_decode($hubKey, true)) !== 32) {
                $this->error('The hub public key is not a WireGuard key.');

                return self::FAILURE;
            }
            Node::withoutEvents(static fn (): bool => SwampNode::node()->update(['wireguard_public_key' => $hubKey]));
            // The hub is up, which is all the vpn role of a Gateway Node stands for.
            $this->activate(RoleName::Vpn);
            // The key every Node peer config names: the Gateway reads it from here when it admits a Node.
            $directory = rtrim(Config::string('orbit.home'), '/').'/wireguard';
            if (! is_dir($directory)) {
                mkdir($directory, 0o700, true);
            }
            file_put_contents($directory.'/public.key', $hubKey."\n");
            chmod($directory.'/public.key', 0o644);
            $this->line('Hub public key recorded.');

            return self::SUCCESS;
        }
        if ($this->option('serving')) {
            $this->activate(RoleName::Swamp);
            $this->line('Swamp role active.');

            return self::SUCCESS;
        }
        $verifier = $this->option('agent-secret-hash');
        if (is_string($verifier) && $verifier !== '') {
            if (preg_match('/\A[a-f0-9]{64}\z/', $verifier) !== 1) {
                $this->error('The agent key verifier is not a SHA-256 digest.');

                return self::FAILURE;
            }
            // The key stays on the machine; only its verifier is recorded, as a rekey does.
            Node::withoutEvents(static fn (): bool => SwampNode::node()->forceFill(['agent_secret_hash' => $verifier])->save());
            $this->line('Agent verifier recorded.');

            return self::SUCCESS;
        }
        $site = $this->option('certificate');
        if ($site === 'swamp') {
            $node = SwampNode::node();
            $paths = $certificates->issue('swamp.orbit', (string) $node->wireguard_ip);
            // The folder the site of the `swamp.orbit` Route names for its certificate (DevelopmentSite::certificateDirectory), which the
            // Gateway's Caddyfile loads after the first publication.
            $route = Route::query()->where('domain', 'swamp.orbit')->firstOrFail();
            $this->line(json_encode(['certificate' => $paths->certificatePath, 'key' => $paths->privateKeyPath,
                'directory' => "/etc/caddy/orbit-certificates/route-{$route->id}/current"], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        if ($site === 'gateway') {
            // The name and address the Gateway site is published under (GatewayWebCaddySiteSource), signed by this machine's CA.
            $node = CaddySiteRoles::serving(RoleName::Gateway)[0]->node ?? null;
            if (! $node instanceof Node || ! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
                $this->error('No Node holds the gateway role with a WireGuard address.');

                return self::FAILURE;
            }
            $paths = $certificates->issue($node->name.'.'.$vpn->domain(), $node->wireguard_ip);
            $this->line(json_encode([
                'certificate' => $paths->certificatePath,
                'key' => $paths->privateKeyPath,
                'root' => dirname($paths->certificatePath, 3).'/root.pem',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        if ($site !== null) {
            $this->error('The certificate is of the swamp or gateway site.');

            return self::FAILURE;
        }
        $checkout = $this->option('checkout');
        if (! is_string($checkout) || $checkout === '') {
            $this->error('Pass --checkout.');

            return self::FAILURE;
        }
        $this->line(json_encode($plan->make($checkout), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function activate(RoleName $role): void
    {
        NodeRole::withoutEvents(static fn (): int => SwampNode::node()->roles()->where('role', $role)
            ->update(['status' => LifecycleStatus::Active, 'failed_step' => null, 'error_code' => null]));
    }
}
