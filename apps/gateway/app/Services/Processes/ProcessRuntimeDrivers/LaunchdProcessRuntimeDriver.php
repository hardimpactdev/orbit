<?php

declare(strict_types=1);

namespace App\Services\Processes\ProcessRuntimeDrivers;

use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Processes\LaunchdPlistRenderer;
use App\Services\Processes\RemoteLaunchdService;
use Throwable;

/**
 * @mago-expect lint:too-many-methods
 */
final readonly class LaunchdProcessRuntimeDriver implements ProcessRuntimeDriver
{
    public function __construct(
        private LaunchdPlistRenderer $renderer,
        private RemoteLaunchdService $launchd,
    ) {}

    public function runtimeUnitName(App $app, Process $process, ?Workspace $workspace = null): string
    {
        return $this->renderer->unitName($app, $process, $workspace);
    }

    public function apply(
        Node $node,
        App $app,
        Process $process,
        ?Workspace $workspace = null,
    ): bool {
        try {
            $unit = $this->runtimeUnitName($app, $process, $workspace);
            $label = $this->renderer->label($unit);

            return $this->launchd
                ->apply(
                    node: $node,
                    label: $label,
                    content: $this->renderer->render($node, $app, $process, $workspace),
                )
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }

    public function remove(Node $node, string $runtimeUnit): bool
    {
        $label = $this->renderer->label($runtimeUnit);
        $plistPath = $this->renderer->plistPath($runtimeUnit, $node);

        return $this->launchd->remove(
            node: $node,
            label: $label,
            plistPath: $plistPath,
        );
    }

    public function cleanupScript(string $runtimeUnit): string
    {
        $label = $this->renderer->label($runtimeUnit);

        return sprintf(
            'launchctl bootout gui/$(id -u)/%1$s >/dev/null 2>&1 || true; launchctl remove %1$s >/dev/null 2>&1 || true; rm -f "$HOME/Library/LaunchAgents/%1$s.plist"; true',
            $label,
        );
    }

    public function start(Node $node, string $runtimeUnit): bool
    {
        return $this->launchd->start($node, $this->renderer->label($runtimeUnit));
    }

    public function stop(Node $node, string $runtimeUnit): bool
    {
        return $this->launchd->stop($node, $this->renderer->label($runtimeUnit));
    }

    public function restart(Node $node, string $runtimeUnit): bool
    {
        return $this->launchd->restart($node, $this->renderer->label($runtimeUnit));
    }

    public function stdoutLogPath(Node $node, string $runtimeUnit): string
    {
        return $this->renderer->stdoutLogPath($runtimeUnit, $node);
    }

    public function stderrLogPath(Node $node, string $runtimeUnit): string
    {
        return $this->renderer->stderrLogPath($runtimeUnit, $node);
    }

    /**
     * @mago-expect lint:excessive-parameter-list
     * @mago-expect lint:no-boolean-flag-parameter
     */
    public function logScript(
        App $app,
        Process $process,
        ?Workspace $workspace,
        string $runtimeUnit,
        int $lines,
        bool $follow,
    ): string {
        $node = $app->node instanceof Node ? $app->node : null;

        if (! $node instanceof Node) {
            return 'printf %s '.escapeshellarg("launchd logs require an app node context.\n");
        }

        $out = $this->renderer->stdoutLogPath($runtimeUnit, $node);
        $err = $this->renderer->stderrLogPath($runtimeUnit, $node);

        return collect([
            'tail',
            '-n',
            escapeshellarg((string) $lines),
            $follow ? '-f' : null,
            escapeshellarg($out),
            escapeshellarg($err),
            '2>&1',
        ])
            ->filter()
            ->implode(' ');
    }
}
