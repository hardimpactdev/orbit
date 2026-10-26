<?php

declare(strict_types=1);

namespace App\Actions\Gateway;

use App\Actions\Nodes\AssignRoleAction;
use App\Data\Gateway\BootstrapGatewayData;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Gateway\GatewaySelfAccessConverger;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Gateway\GatewayVpnConverger;
use App\Domain\Gateway\GatewayWebConverger;
use App\Domain\Nodes\NodeAgentRuntime;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Models\Node;
use Throwable;

final readonly class BootstrapGatewayAction
{
    /** @var list<RoleName> */
    private const array OwnedRoles = [RoleName::Gateway, RoleName::Vpn];

    public function __construct(
        private AssignRoleAction $assignRole,
        private GatewayBootstrapIdentityValidator $identity,
        private GatewayOperatingSystemGuard $operatingSystem,
        private VpnSettings $vpnSettings,
        private ProcessRunner $processes,
        private AgentCommandRunner $commands,
        private GatewayVpnConverger $vpn,
        private GatewayWebConverger $web,
        private GatewaySelfAccessConverger $selfAccess,
        private PrivateDnsManager $dns,
        private string $orbitHome,
        private NodeAgentRuntime $agent,
        private ?GatewayServingHost $servingHost = null,
    ) {}

    public function execute(BootstrapGatewayData $data): Node
    {
        $this->identity->validate($data);
        $this->operatingSystem->assertSupported();

        $node = Node::query()->updateOrCreate(
            ['name' => $data->name],
            [
                'status' => LifecycleStatus::Provisioning,
                'platform' => 'linux',
                'architecture' => php_uname('m'),
                'public_ssh_host' => $data->publicHost,
                'public_ssh_port' => 22,
                'user' => 'orbit',
                'wireguard_ip' => $data->wireguardIp,
                'failed_step' => null,
                'error_code' => null,
            ],
        );

        try {
            foreach (self::OwnedRoles as $role) {
                $this->assignRole
                    ->execute($node, $role)
                    ->update([
                        'status' => LifecycleStatus::Provisioning,
                        'failed_step' => null,
                        'error_code' => null,
                    ]);
            }

            $this->vpnSettings->configure(
                subnet: $data->wireguardSubnet,
                port: $data->wireguardPort,
                endpoint: $data->wireguardEndpoint,
                dnsServer: $data->dnsServer,
                domain: $data->domain,
                privateInterface: $data->privateInterface,
            );

            $this->ensureDirectories();
            $wireGuardPublicKey = $this->prepareIdentity($node);
            $node->update([
                'ssh_host_fingerprint' => $this->selfAccess->converge($node),
            ]);
            $node->update(['wireguard_public_key' => $wireGuardPublicKey]);
            $this->ensureCertificateAuthority();
            $this->vpn->converge($node, $data);
            $this->web->converge($node, "{$data->name}.{$data->domain}", $data->wireguardIp);
            $this->dns->converge($node);
            $active = [
                'status' => LifecycleStatus::Active,
                'failed_step' => null,
                'error_code' => null,
            ];
            $node->update($active);
            $node->roles()->whereIn('role', self::OwnedRoles)->update($active);
            ($this->servingHost ?? app(GatewayServingHost::class))->remember($node);
            try {
                $this->agent->converge($node);
            } catch (Throwable $exception) {
                throw new NodeProvisioningException(
                    step: 'agent-install',
                    errorCode: 'agent.install_failed',
                    message: 'The Gateway observer could not be configured.',
                    previous: $exception,
                );
            }

        } catch (RuntimeConvergenceException $exception) {
            $failure = new NodeProvisioningException(
                step: $exception->step,
                errorCode: $exception->errorCode,
                message: $exception->getMessage(),
                previous: $exception,
                result: $exception->result,
            );
            $this->markFailed($node, $failure);

            throw $failure;
        } catch (Throwable $exception) {
            $failure = $exception instanceof NodeProvisioningException
                ? $exception
                : new NodeProvisioningException(
                    step: 'unknown',
                    errorCode: 'gateway.bootstrap_failed',
                    message: 'Gateway bootstrap failed.',
                    previous: $exception,
                );
            $this->markFailed($node, $failure);

            throw $failure;
        }

        return $node->load('roles');
    }

    private function markFailed(Node $node, NodeProvisioningException $exception): void
    {
        $failure = [
            'status' => LifecycleStatus::Failed,
            'failed_step' => $exception->step,
            'error_code' => $exception->errorCode,
        ];
        $node->update($failure);
        $node->roles()->whereIn('role', self::OwnedRoles)->update($failure);
    }

    private function ensureDirectories(): void
    {
        foreach (['', 'ca', 'generated', 'logs'] as $directory) {
            $path = rtrim(string: $this->orbitHome.'/'.$directory, characters: '/');

            if (! is_dir($path) && ! mkdir(directory: $path, permissions: 0o700, recursive: true) && ! is_dir($path)) {
                throw new NodeProvisioningException(
                    step: 'gateway-directories',
                    errorCode: 'gateway.directory_failed',
                    message: "Could not create gateway directory [{$path}].",
                );
            }

            chmod(filename: $path, permissions: 0o700);
        }
    }

    private function prepareIdentity(Node $node): string
    {
        try {
            $this->commands->install($node);
        } catch (Throwable $exception) {
            throw new NodeProvisioningException('agent-install', 'agent.install_failed', 'The Gateway agent could not be installed.', previous: $exception);
        }

        try {
            $result = $this->commands->run($node, 'gateway.prepare-identity', ['orbit_home' => $this->orbitHome]);
        } catch (AgentCommandRefused $exception) {
            [$step, $code] = match ($exception->errorCode) {
                'gateway.directory_failed' => ['gateway-directories', 'gateway.directory_failed'],
                'vpn.key_generation_failed' => ['wireguard-private-key', 'vpn.key_generation_failed'],
                default => ['gateway-ssh-key', 'gateway.ssh_key_failed'],
            };
            throw new NodeProvisioningException($step, $code, $exception->getMessage(), previous: $exception, result: AgentStep::output($exception));
        } catch (AgentRunnerFailure $exception) {
            throw new NodeProvisioningException('gateway-ssh-key', 'gateway.ssh_key_failed', 'The Gateway identity command could not run.', previous: $exception, result: $exception->result);
        }
        $publicKey = $result['wireguard_public_key'] ?? null;
        if (! is_string($publicKey) || strlen((string) base64_decode($publicKey, true)) !== 32) {
            throw new NodeProvisioningException('wireguard-public-key', 'vpn.key_generation_failed', 'The agent returned an invalid Gateway WireGuard public key.');
        }

        return $publicKey;
    }

    private function ensureCertificateAuthority(): void
    {
        $caDirectory = $this->orbitHome.'/ca';
        $lockPath = $caDirectory.'/root.lock';
        $lock = fopen(filename: $lockPath, mode: 'c+');

        if ($lock === false) {
            throw new NodeProvisioningException(
                step: 'ca-root-lock',
                errorCode: 'ca.lock_failed',
                message: 'Could not open the Orbit root CA publication lock.',
            );
        }

        try {
            chmod(filename: $lockPath, permissions: 0o600);

            if (! flock($lock, LOCK_EX)) {
                throw new NodeProvisioningException(
                    step: 'ca-root-lock',
                    errorCode: 'ca.lock_failed',
                    message: 'Could not acquire the Orbit root CA publication lock.',
                );
            }

            $this->ensureLockedCertificateAuthority($caDirectory);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function ensureLockedCertificateAuthority(string $caDirectory): void
    {
        $privateKey = $caDirectory.'/root.key';
        $certificate = $caDirectory.'/root.pem';
        $candidateDirectory = $caDirectory.'/.root-ca.candidate';
        $privateKeyExists = is_file($privateKey);
        $certificateExists = is_file($certificate);

        if ($privateKeyExists !== $certificateExists) {
            throw new NodeProvisioningException(
                step: 'ca-root-validate',
                errorCode: 'ca.invalid_state',
                message: 'The Orbit root CA state is partial; restore the missing root file.',
            );
        }

        $this->cleanupCertificateAuthorityCandidate($candidateDirectory);

        if ($privateKeyExists && $certificateExists) {
            if (! $this->certificateAuthorityPairIsValid($privateKey, $certificate)) {
                throw new NodeProvisioningException(
                    step: 'ca-root-validate',
                    errorCode: 'ca.invalid_state',
                    message: 'The existing Orbit root CA key and certificate pair is invalid.',
                );
            }

            $this->protectCertificateAuthorityPair($privateKey, $certificate);

            return;
        }

        $candidatePrivateKey = $candidateDirectory.'/root.key';
        $candidateCertificate = $candidateDirectory.'/root.pem';

        try {
            if (! mkdir(directory: $candidateDirectory, permissions: 0o700) && ! is_dir($candidateDirectory)) {
                throw new NodeProvisioningException(
                    step: 'ca-root-candidate',
                    errorCode: 'ca.candidate_failed',
                    message: 'Could not create the Orbit root CA candidate directory.',
                );
            }

            chmod(filename: $candidateDirectory, permissions: 0o700);
            $this->run('ca-private-key', 'ca.key_generation_failed', [
                'openssl',
                'genrsa',
                '-out',
                $candidatePrivateKey,
                '4096',
            ]);
            chmod(filename: $candidatePrivateKey, permissions: 0o600);
            $this->run('ca-root-certificate', 'ca.certificate_generation_failed', [
                'openssl',
                'req',
                '-x509',
                '-new',
                '-key',
                $candidatePrivateKey,
                '-out',
                $candidateCertificate,
                '-days',
                '3650',
                '-subj',
                '/CN=Orbit Root CA',
                '-addext',
                'basicConstraints=critical,CA:TRUE',
                '-addext',
                'keyUsage=critical,keyCertSign,cRLSign',
            ]);
            chmod(filename: $candidateCertificate, permissions: 0o644);

            if (! $this->certificateAuthorityPairIsValid($candidatePrivateKey, $candidateCertificate)) {
                throw new NodeProvisioningException(
                    step: 'ca-root-validate',
                    errorCode: 'ca.invalid_candidate',
                    message: 'The generated Orbit root CA key and certificate pair is invalid.',
                );
            }

            $this->publishCertificateAuthorityPair(
                $candidatePrivateKey,
                $candidateCertificate,
                $privateKey,
                $certificate,
            );
            $this->protectCertificateAuthorityPair($privateKey, $certificate);
        } finally {
            $this->cleanupCertificateAuthorityCandidate($candidateDirectory);
        }
    }

    private function certificateAuthorityPairIsValid(string $privateKeyPath, string $certificatePath): bool
    {
        $certificate = file_get_contents($certificatePath);
        $privateKey = file_get_contents($privateKeyPath);

        if (! is_string($certificate) || ! is_string($privateKey)) {
            return false;
        }

        $parsedCertificate = openssl_x509_read(certificate: $certificate);
        $parsedPrivateKey = openssl_pkey_get_private($privateKey);
        $privateKeyDetails = $parsedPrivateKey !== false
            ? openssl_pkey_get_details($parsedPrivateKey)
            : false;
        $details = $parsedCertificate !== false ? openssl_x509_parse($parsedCertificate) : false;
        $basicConstraints = is_array($details)
            ? $details['extensions']['basicConstraints'] ?? null
            : null;
        $validFrom = is_array($details) ? $details['validFrom_time_t'] : null;
        $validTo = is_array($details) ? $details['validTo_time_t'] : null;
        $now = time();

        return
            $parsedCertificate !== false
            && $parsedPrivateKey !== false
            && is_array($privateKeyDetails)
            && ($privateKeyDetails['type'] ?? null) === OPENSSL_KEYTYPE_RSA
            && ($privateKeyDetails['bits'] ?? null) === 4096
            && is_string($basicConstraints)
            && str_contains($basicConstraints, 'CA:TRUE')
            && is_int($validFrom)
            && $validFrom <= $now
            && is_int($validTo)
            && $validTo >= $now
            && openssl_x509_check_private_key($parsedCertificate, $parsedPrivateKey);
    }

    private function publishCertificateAuthorityPair(
        string $candidatePrivateKey,
        string $candidateCertificate,
        string $privateKey,
        string $certificate,
    ): void {
        $privateKeyPublished = false;

        try {
            if (! rename($candidatePrivateKey, $privateKey)) {
                throw new \RuntimeException('Could not publish the Orbit root CA private key.');
            }

            $privateKeyPublished = true;

            if (! rename($candidateCertificate, $certificate)) {
                throw new \RuntimeException('Could not publish the Orbit root CA certificate.');
            }
        } catch (Throwable $exception) {
            if ($privateKeyPublished && is_file($privateKey)) {
                rename($privateKey, $candidatePrivateKey);
            }

            throw new NodeProvisioningException(
                step: 'ca-root-publish',
                errorCode: 'ca.publish_failed',
                message: 'Could not publish the validated Orbit root CA pair.',
                previous: $exception,
            );
        }
    }

    private function protectCertificateAuthorityPair(string $privateKey, string $certificate): void
    {
        chmod(filename: $privateKey, permissions: 0o600);
        chmod(filename: $certificate, permissions: 0o644);
    }

    private function cleanupCertificateAuthorityCandidate(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (['root.key', 'root.pem'] as $filename) {
            $path = $directory.'/'.$filename;

            if (is_file($path)) {
                unlink($path);
            }
        }

        rmdir($directory);
    }

    /** @param non-empty-list<string> $arguments */
    private function run(
        string $step,
        string $errorCode,
        array $arguments,
        ?string $input = null,
    ): CommandResult {
        $result = $this->processes->run(new ProcessInvocation(
            arguments: $arguments,
            timeout: 60.0,
            input: $input,
        ));

        if (! $result->succeeded()) {
            throw new NodeProvisioningException(
                step: $step,
                errorCode: $errorCode,
                message: "Gateway bootstrap step [{$step}] failed.",
                result: $result,
            );
        }

        return $result;
    }
}
