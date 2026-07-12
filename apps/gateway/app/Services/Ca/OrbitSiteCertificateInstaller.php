<?php

declare(strict_types=1);

namespace App\Services\Ca;

use App\Contracts\SiteCertificateInstaller;
use App\Models\Node;
use App\Services\Nodes\NodeHostPaths;
use App\Services\RemoteShell\RunsInternalCommands;
use Illuminate\Support\Facades\File;
use RuntimeException;

final readonly class OrbitSiteCertificateInstaller implements SiteCertificateInstaller
{
    public function __construct(
        private OrbitCaService $ca,
        private ?RunsInternalCommands $localExecutor = null,
    ) {}

    /**
     * @return array{cert: string, key: string}
     */
    public function ensureFor(Node $node, string $host): array
    {
        $this->assertSafeHost($host);

        $local = $this->ca->issueLeaf($host);
        $remote = $this->expectedPathsFor($node, $host);

        $result = $this->localExecutor()->runInternal(
            node: $node,
            commandName: 'internal:site-certificate:install',
            transportOptions: [
                'metadata' => [
                    'ORBIT_OPERATION_ID' => 'site-certificate.install',
                ],
                'input' => json_encode([
                    'cert_path' => $remote['cert'],
                    'key_path' => $remote['key'],
                    'cert' => File::get($local['cert']),
                    'key' => File::get($local['key']),
                    'owner' => $this->owner($node),
                ], JSON_THROW_ON_ERROR),
                'redact_stdout' => true,
                'redact_stderr' => true,
                'throw' => false,
            ],
        );

        if (! $result->successful()) {
            throw new RuntimeException('Site certificate install failed.');
        }

        return $remote;
    }

    /**
     * @return array{cert: string, key: string}
     */
    public function expectedPathsFor(Node $node, string $host): array
    {
        $this->assertSafeHost($host);

        $base = $this->nodeHome($node).'/.config/orbit/certs';

        return [
            'cert' => "{$base}/{$host}.crt",
            'key' => "{$base}/{$host}.key",
        ];
    }

    private function localExecutor(): RunsInternalCommands
    {
        return $this->localExecutor ?? app(RunsInternalCommands::class);
    }

    private function owner(Node $node): ?string
    {
        $user = $node->user ?: 'orbit';

        if ($user === 'root') {
            return null;
        }

        return $user;
    }

    private function nodeHome(Node $node): string
    {
        return NodeHostPaths::homeDirectoryFor($node->platform, $node->user);
    }

    private function assertSafeHost(string $host): void
    {
        if ($host === '' || preg_match('#[/\\\\\s]#', $host) === 1) {
            throw new RuntimeException("Invalid host for site certificate: {$host}");
        }
    }
}
