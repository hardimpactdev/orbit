<?php

declare(strict_types=1);

namespace App\Services\WebSockets;

use App\Models\Node;
use App\Services\Ca\OrbitCaService;
use App\Services\RemoteShell\RunsInternalCommands;
use Illuminate\Support\Facades\File;
use RuntimeException;

class WebSocketCertificateInstaller
{
    public const CertificateDirectory = '/etc/orbit/certs';

    public function __construct(
        private readonly OrbitCaService $ca,
        private readonly WebSocketBackendName $backendName,
        private readonly ?RunsInternalCommands $localExecutor = null,
    ) {}

    /**
     * Install backend TLS material through the node-local executor. The target
     * agent writes host-owned `/etc/orbit/certs` artifacts using fixed argv.
     *
     * @see apps/docs/content/execution-lanes.md
     *
     * @return array{cert: string, key: string}
     */
    public function ensureFor(Node $node): array
    {
        $backendName = $this->backendName->forNode($node);
        $wireGuardAddress = $this->wireGuardAddress($node);
        $local = $this->ca->issueLeaf($backendName, [$wireGuardAddress]);
        $remote = $this->pathsForBackend($backendName);

        $result = $this->localExecutor()->runInternal(
            node: $node,
            commandName: 'internal:site-certificate:install',
            transportOptions: [
                'throw' => true,
                'metadata' => [
                    'ORBIT_OPERATION_ID' => 'websocket-certificate.install',
                ],
                'input' => json_encode([
                    'cert_path' => $remote['cert'],
                    'key_path' => $remote['key'],
                    'cert' => File::get($local['cert']),
                    'key' => File::get($local['key']),
                    'owner' => null,
                ], JSON_THROW_ON_ERROR),
                'redact_stdout' => true,
                'redact_stderr' => true,
            ],
        );

        if (! $result->successful()) {
            throw new RuntimeException('Websocket certificate install failed.');
        }

        return $remote;
    }

    /**
     * @return array{cert: string, key: string}
     */
    public function expectedPathsFor(Node $node): array
    {
        return $this->pathsForBackend($this->backendName->forNode($node));
    }

    /**
     * @return array{cert: string, key: string}
     */
    private function pathsForBackend(string $backendName): array
    {
        return [
            'cert' => self::CertificateDirectory."/{$backendName}.crt",
            'key' => self::CertificateDirectory."/{$backendName}.key",
        ];
    }

    private function wireGuardAddress(Node $node): string
    {
        $wireGuardAddress = trim((string) $node->wireguard_address);

        if ($wireGuardAddress === '') {
            throw new RuntimeException('The websocket backend requires a WireGuard address.');
        }

        return $wireGuardAddress;
    }

    private function localExecutor(): RunsInternalCommands
    {
        return $this->localExecutor ?? app(RunsInternalCommands::class);
    }
}
