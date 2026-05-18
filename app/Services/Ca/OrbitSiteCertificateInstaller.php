<?php

declare(strict_types=1);

namespace App\Services\Ca;

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Models\Node;
use Illuminate\Support\Facades\File;
use RuntimeException;

final readonly class OrbitSiteCertificateInstaller implements SiteCertificateInstaller
{
    public function __construct(
        private OrbitCaService $ca,
        private RemoteShell $remoteShell,
    ) {}

    /**
     * @return array{cert: string, key: string}
     */
    public function ensureFor(Node $node, string $host): array
    {
        $this->assertSafeHost($host);

        $local = $this->ca->issueLeaf($host);
        $remote = $this->expectedPathsFor($node, $host);

        $this->remoteShell->run($node, $this->installScript(
            certPath: $remote['cert'],
            cert: File::get($local['cert']),
            keyPath: $remote['key'],
            key: File::get($local['key']),
            traversalDirs: $this->traversalDirsFor($node, dirname($remote['cert'])),
        ), ['throw' => true]);

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

    /**
     * @param  list<string>  $traversalDirs
     */
    private function installScript(string $certPath, string $cert, string $keyPath, string $key, array $traversalDirs): string
    {
        $dirs = implode(' ', array_map(escapeshellarg(...), $traversalDirs));

        return sprintf(
            <<<'SH'
set -e
sudo install -d -m 0755 %s
printf %%s %s | base64 -d | sudo tee %s >/dev/null
printf %%s %s | base64 -d | sudo tee %s >/dev/null
sudo chmod 0644 %s
sudo chmod 0600 %s
if getent group caddy >/dev/null 2>&1; then
    sudo chgrp caddy %s %s
    sudo chmod g+rx %s
    sudo chmod 0640 %s
fi
SH,
            escapeshellarg(dirname($certPath)),
            escapeshellarg(base64_encode($cert)),
            escapeshellarg($certPath),
            escapeshellarg(base64_encode($key)),
            escapeshellarg($keyPath),
            escapeshellarg($certPath),
            escapeshellarg($keyPath),
            $dirs,
            escapeshellarg($keyPath),
            $dirs,
            escapeshellarg($keyPath),
        );
    }

    /**
     * @return list<string>
     */
    private function traversalDirsFor(Node $node, string $certDir): array
    {
        $home = $this->nodeHome($node);

        return array_values(array_unique([
            $home,
            "{$home}/.config",
            "{$home}/.config/orbit",
            $certDir,
        ]));
    }

    private function nodeHome(Node $node): string
    {
        $user = $node->user ?: 'orbit';

        return $user === 'root' ? '/root' : "/home/{$user}";
    }

    private function assertSafeHost(string $host): void
    {
        if ($host === '' || preg_match('#[/\\\\\s]#', $host) === 1) {
            throw new RuntimeException("Invalid host for site certificate: {$host}");
        }
    }
}
