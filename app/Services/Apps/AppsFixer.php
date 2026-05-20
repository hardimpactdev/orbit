<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Models\App;
use App\Models\Node;
use App\Services\Php\PhpFpmServiceReloader;
use App\Services\Php\PhpFpmSystemdHardening;
use RuntimeException;

final readonly class AppsFixer
{
    public function __construct(
        private RemoteShell $remoteShell,
        private AppFpmPoolRenderer $fpmPoolRenderer,
        private PhpFpmServiceReloader $fpmServiceReloader,
        private PhpFpmSystemdHardening $fpmSystemdHardening,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function fix(App $app, DriftEntry $entry): ?array
    {
        if (! in_array($entry->key, [
            'app.fpm_config_missing',
            'app.fpm_config_mismatch',
            'app.security.system_user',
            'app.security.fs_permissions',
            'app.security.fpm_pool_isolation',
            'app.security.fpm_systemd_hardening',
        ], true)) {
            return null;
        }

        $app->loadMissing('node');

        $node = $app->node;

        if (! $node instanceof Node) {
            return null;
        }

        $this->remoteShell->run($node, $this->installScript($app), ['throw' => true]);

        return [
            'family' => 'app',
            'node' => $node->name,
            'code' => $entry->key,
            'key' => $entry->key,
            'mode' => 'fix',
            'status' => 'completed',
            'summary' => "Re-applied app runtime hardening for {$app->name}.",
            'details' => [
                'app' => $app->name,
                'path' => $this->fpmPoolRenderer->path($app),
            ],
        ];
    }

    private function installScript(App $app): string
    {
        $content = $this->fpmPoolRenderer->content($app);
        $path = $this->fpmPoolRenderer->path($app);
        $service = $this->fpmPoolRenderer->service($app);
        $user = $this->fpmPoolRenderer->runtimeUser($app);
        $home = $user === 'root' ? '/root' : "/home/{$user}";
        $node = $app->node;

        if (! $node instanceof Node) {
            throw new RuntimeException("App '{$app->name}' has no owning node.");
        }

        $hardening = $this->fpmSystemdHardening->contentForNode($node, $app->php_version);

        return sprintf(
            <<<'SH'
set -e
if ! id -u %s >/dev/null 2>&1; then
    sudo useradd --system --create-home --home-dir %s --shell /usr/sbin/nologin %s
fi
sudo install -d -m 0750 -o %s -g %s %s
sudo install -d -m 0755 %s %s %s
if [ -d %s ]; then sudo chown -R %s:%s %s; fi
printf %%s %s | base64 -d | sudo tee %s >/dev/null
%s
%s
SH,
            escapeshellarg($user),
            escapeshellarg($home),
            escapeshellarg($user),
            escapeshellarg($user),
            escapeshellarg($user),
            escapeshellarg($home),
            escapeshellarg(dirname($path)),
            escapeshellarg(dirname($this->fpmPoolRenderer->socketPath($app))),
            escapeshellarg(dirname($this->fpmPoolRenderer->logPath($app))),
            escapeshellarg($app->path),
            escapeshellarg($user),
            escapeshellarg($user),
            escapeshellarg($app->path),
            escapeshellarg(base64_encode($content)),
            escapeshellarg($path),
            $this->fpmSystemdHardening->installScript($app->php_version, $hardening),
            $this->fpmServiceReloader->reloadOrRestartScript($service),
        );
    }
}
