<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Contracts\RemoteShell;
use App\Models\App;
use App\Services\Apps\AppFpmPoolRenderer;
use RuntimeException;

final readonly class EnactAppRuntime
{
    public function __construct(
        private RemoteShell $remoteShell,
        private EnsureAppProxyRoute $ensureAppProxyRoute,
        private EnsureAppProcessRuntimeUnits $ensureAppProcessRuntimeUnits,
        private AppFpmPoolRenderer $fpmPoolRenderer,
    ) {}

    /**
     * @return list<array<string, string>>
     */
    public function handle(App $app): array
    {
        $app->loadMissing('node');

        if ($app->node === null) {
            throw new RuntimeException("App '{$app->name}' has no owning node.");
        }

        $result = $this->remoteShell->run($app->node, sprintf(
            'test -x %1$s || command -v %2$s >/dev/null 2>&1 || command -v %3$s >/dev/null 2>&1 || command -v php-fpm >/dev/null 2>&1',
            escapeshellarg("/usr/sbin/php-fpm{$app->php_version}"),
            escapeshellarg("php-fpm{$app->php_version}"),
            escapeshellarg("php{$app->php_version}-fpm"),
        ));

        if ($result->successful()) {
            $this->remoteShell->run($app->node, $this->renderFpmPoolScript($app));

            return [
                ...$this->ensureAppProxyRoute->handle($app),
                ...$this->ensureAppProcessRuntimeUnits->handle($app),
            ];
        }

        return [[
            'code' => 'app.php_version_unavailable',
            'family' => 'app',
            'message' => "PHP {$app->php_version} FPM is not available on '{$app->node->name}'. Run doctor to converge app runtime artifacts.",
            'next_command' => 'doctor --family=app --fix',
        ]];
    }

    private function renderFpmPoolScript(App $app): string
    {
        $node = $app->node;

        if ($node === null) {
            throw new RuntimeException("App '{$app->name}' has no owning node.");
        }

        $poolPath = $this->fpmPoolRenderer->path($app);
        $service = $this->fpmPoolRenderer->service($app);
        $content = $this->fpmPoolRenderer->content($app);

        return sprintf(
            <<<'SH'
sudo mkdir -p %s %s %s
cat <<'ORBIT_FPM_POOL' | sudo tee %s >/dev/null
%s
ORBIT_FPM_POOL
sudo systemctl reload %s || sudo systemctl reload php-fpm
SH,
            escapeshellarg(dirname($poolPath)),
            escapeshellarg(dirname($this->fpmPoolRenderer->socketPath($app))),
            escapeshellarg(dirname($this->fpmPoolRenderer->logPath($app))),
            escapeshellarg($poolPath),
            $content,
            escapeshellarg($service),
        );
    }
}
