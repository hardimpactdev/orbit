<?php

declare(strict_types=1);

namespace App\Actions\Gateway;

use App\Actions\Nodes\AssignRoleAction;
use App\Data\Gateway\BootstrapGatewayData;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\Certificates\OrbitRootCa;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Models\DatabaseConnection;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * Initialises a Gateway from its own code, on the machine it runs on (docs/cli/task/vm-fleet-build.mmd, "Converge itself"):
 * its Node and role records, the VPN settings, the Orbit root CA, its SSH key and the host key of its machine. Root-of-trust
 * keys are made here and stay here. It starts no service and writes nothing under `/etc`: the WireGuard hub, whose private
 * key is made on the Node, and the Gateway's roles are applied by Swamp afterwards.
 */
final readonly class BootstrapGatewayAction
{
    /** @var list<RoleName> */
    private const array OwnedRoles = [RoleName::Gateway, RoleName::Vpn];

    /** The Redis Database connection of the Gateway queue: the Valkey server on this machine. */
    public const string QueueConnection = 'gateway-queue';

    /** The Node of the machine that bootstraps and then operates this Gateway; stage 2 records its key by this name. */
    public const string OperatorName = 'operator';

    /** Where Swamp serves on its machine, behind the `swamp.orbit` Route. */
    private const string SwampUpstream = 'http://127.0.0.1:9418';

    public function __construct(
        private AssignRoleAction $assignRole,
        private VpnSettings $vpnSettings,
        private ProcessRunner $processes,
        private GatewayServingHost $servingHost,
        private string $hostKeyPath = '/etc/ssh/ssh_host_ed25519_key.pub',
    ) {}

    public function execute(BootstrapGatewayData $data): Node
    {
        $this->validate($data);
        $home = rtrim(Config::string('orbit.home'), '/');
        $this->directories($home);
        $this->sshKey($home);
        $this->certificateAuthority($home);
        $hostKey = $this->hostKey();

        try {
            // No model events: recording the pin would start a Swamp run, and this Gateway has no Swamp yet. The pin is published
            // when Swamp serves.
            $node = Node::withoutEvents(fn (): Node => DB::transaction(function () use ($data, $hostKey): Node {
                $node = Node::query()->firstOrNew(['name' => $data->name]);
                $node->forceFill([
                    'status' => LifecycleStatus::Provisioning,
                    'platform' => 'linux',
                    'architecture' => php_uname('m'),
                    'public_ssh_host' => $data->publicHost,
                    'public_ssh_port' => 22,
                    'user' => 'orbit',
                    'wireguard_ip' => $data->wireguardIp,
                    'ssh_host_key_type' => 'ssh-ed25519',
                    'ssh_host_key' => $hostKey['key'],
                    'ssh_host_fingerprint' => $hostKey['fingerprint'],
                    'failed_step' => null,
                    'error_code' => null,
                ])->save();
                foreach (self::OwnedRoles as $role) {
                    $this->assignRole->execute($node, $role);
                }
                $this->vpnSettings->configure(
                    subnet: $data->wireguardSubnet,
                    port: $data->wireguardPort,
                    endpoint: $data->wireguardEndpoint,
                    dnsServer: $data->dnsServer,
                    domain: $data->domain,
                    privateInterface: $data->privateInterface,
                );
                // The app runs, so the Gateway Node and its role are live. The VPN role stays provisioning until Swamp applies the hub.
                $node->update(['status' => LifecycleStatus::Active]);
                $node->roles()->where('role', RoleName::Gateway)->update(['status' => LifecycleStatus::Active, 'failed_step' => null, 'error_code' => null]);
                // The machine of this Gateway runs Swamp, so its Node holds the swamp role; the Gateway finds the Swamp Node by that role.
                // The role is not assignable while a Node provisions, so it comes last, and it stays provisioning until Swamp serves.
                $this->assignRole->execute($node, RoleName::Swamp);
                // The Gateway reaches Swamp through `swamp.orbit`, so its Route exists before the first Caddyfile is published: a
                // publication that lacked it would drop the front that the bootstrap put there.
                $this->swampRoute($node);
                $this->operator($data, $node);
                DatabaseConnection::query()->updateOrCreate(['slug' => self::QueueConnection],
                    ['driver' => DatabaseDriver::Redis, 'node_id' => $node->id, 'host' => '127.0.0.1', 'port' => 6379]);

                return $node;
            }));
        } catch (Throwable $exception) {
            throw new NodeProvisioningException(
                step: 'gateway-records',
                errorCode: 'gateway.bootstrap_failed',
                message: 'Gateway bootstrap could not record the Gateway.',
                previous: $exception,
            );
        }
        $this->servingHost->remember($node);

        return $node->load('roles');
    }

    /**
     * The machine that runs the Gateway's first commands: a Node without roles at its WireGuard address, with access to the Gateway, so
     * it holds Gateway authority. Its key comes from that machine when it joins; until then it has none.
     */
    private function operator(BootstrapGatewayData $data, Node $gateway): void
    {
        if ($data->operatorIp === null) {
            return;
        }
        $operator = Node::query()->firstOrNew(['name' => self::OperatorName]);
        $operator->forceFill(['status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => $data->operatorIp,
            'user' => 'orbit', 'wireguard_ip' => $data->operatorIp])->save();
        $operator->accessibleNodes()->syncWithoutDetaching([$gateway->id]);
    }

    private function swampRoute(Node $node): void
    {
        // A Route is stored pending, and the update after its proxy is stored makes it active, as the Route workflow does.
        $route = Route::query()->firstOrCreate(['domain' => 'swamp.orbit'], [
            'kind' => RouteKind::CustomProxy,
            'node_id' => $node->id,
            'provenance' => RouteProvenance::Explicit,
            'publication' => RoutePublication::Private,
            'status' => RouteStatus::Pending,
        ]);
        $route->customProxy()->updateOrCreate([], ['node_id' => $node->id, 'upstream' => self::SwampUpstream]);
        // Set by hand: the bootstrap records without model events, which would set it on an authoritative Route.
        $route->forceFill(['status' => RouteStatus::Active, 'sites_published' => true])->save();
    }

    private function validate(BootstrapGatewayData $data): void
    {
        if (! IpUtils::checkIp4($data->wireguardIp, $data->wireguardSubnet) || $data->publicHost === '' || $data->name === ''
            || ($data->operatorIp !== null && (! IpUtils::checkIp4($data->operatorIp, $data->wireguardSubnet) || $data->operatorIp === $data->wireguardIp))) {
            throw new NodeProvisioningException(
                step: 'gateway-identity',
                errorCode: 'gateway.bootstrap_invalid',
                message: 'The WireGuard addresses must lie in the WireGuard subnet, the operator\'s apart from the Gateway\'s, and the public host and name must not be empty.',
                status: 422,
            );
        }
        $release = @file_get_contents('/etc/os-release');
        if (! is_string($release) || preg_match('/^ID=ubuntu$/m', $release) !== 1) {
            throw new NodeProvisioningException(
                step: 'gateway-os',
                errorCode: 'gateway.os_unsupported',
                message: 'A Gateway runs on Ubuntu.',
                status: 422,
            );
        }
    }

    private function directories(string $home): void
    {
        foreach (['', 'ca', 'ssh', 'generated', 'logs'] as $directory) {
            $path = rtrim($home.'/'.$directory, '/');
            if (! is_dir($path) && ! mkdir(directory: $path, permissions: 0o700, recursive: true) && ! is_dir($path)) {
                throw new NodeProvisioningException('gateway-directories', 'gateway.directory_failed', "Could not create gateway directory [{$path}].");
            }
            chmod(filename: $path, permissions: 0o700);
        }
    }

    private function sshKey(string $home): void
    {
        $key = $home.'/ssh/id_ed25519';
        if (is_file($key) && is_file($key.'.pub')) {
            return;
        }
        @unlink($key);
        @unlink($key.'.pub');
        $this->run('gateway-ssh-key', 'gateway.ssh_key_failed', ['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', 'orbit-gateway', '-f', $key]);
        chmod(filename: $key, permissions: 0o600);
    }

    /** One root CA, kept for the life of the Gateway: an existing valid pair is never replaced. */
    private function certificateAuthority(string $home): void
    {
        $key = $home.'/ca/root.key';
        $certificate = $home.'/ca/root.pem';
        if (is_file($key) !== is_file($certificate)) {
            throw new NodeProvisioningException('ca-root-validate', 'ca.invalid_state', 'The Orbit root CA state is partial; restore the missing root file.');
        }
        if (! is_file($key)) {
            $this->run('ca-private-key', 'ca.key_generation_failed', ['openssl', 'genrsa', '-out', $key, '4096']);
            chmod(filename: $key, permissions: 0o600);
            $this->run('ca-root-certificate', 'ca.certificate_generation_failed', [
                'openssl', 'req', '-x509', '-new', '-key', $key, '-out', $certificate, '-days', '3650', '-subj', '/CN=Orbit Root CA',
                '-addext', 'basicConstraints=critical,CA:TRUE', '-addext', 'keyUsage=critical,keyCertSign,cRLSign',
            ]);
        }
        chmod(filename: $certificate, permissions: 0o644);
        $pem = file_get_contents($certificate);
        $private = file_get_contents($key);
        if (! is_string($pem) || ! is_string($private) || ! OrbitRootCa::pairIsValid($pem, $private, time())) {
            throw new NodeProvisioningException('ca-root-validate', 'ca.invalid_state', 'The Orbit root CA key and certificate pair is invalid.');
        }
    }

    /** @return array{key:string,fingerprint:string} */
    private function hostKey(): array
    {
        $line = @file_get_contents($this->hostKeyPath);
        $parts = is_string($line) ? explode(' ', trim($line)) : [];
        $bytes = ($parts[0] ?? '') === 'ssh-ed25519' && isset($parts[1]) ? base64_decode($parts[1], true) : false;
        if ($bytes === false || $bytes === '') {
            throw new NodeProvisioningException('gateway-host-key', 'gateway.host_key_missing', 'The machine has no readable ed25519 SSH host key.');
        }

        return ['key' => $parts[1], 'fingerprint' => 'SHA256:'.rtrim(base64_encode(hash('sha256', $bytes, true)), '=')];
    }

    /** @param non-empty-list<string> $arguments */
    private function run(string $step, string $errorCode, array $arguments): CommandResult
    {
        $result = $this->processes->run(new ProcessInvocation(arguments: $arguments, timeout: 120.0));
        if (! $result->succeeded()) {
            throw new NodeProvisioningException(step: $step, errorCode: $errorCode, message: "Gateway bootstrap step [{$step}] failed.", result: $result);
        }

        return $result;
    }
}
