<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Apps\LaravelViteDevServerEnvironment;
use App\Services\Nodes\NodeHostPaths;
use InvalidArgumentException;

/**
 * @mago-expect lint:too-many-methods
 */
final readonly class LaunchdPlistRenderer
{
    public function __construct(
        private LaravelViteDevServerEnvironment $vite,
    ) {}

    public function unitName(App $app, Process $process, ?Workspace $workspace = null): string
    {
        $process->loadMissing('owner');

        if ($process->owner instanceof Node) {
            return $this->assertUnitName($process->name);
        }

        $this->assertIdentitySlug($app->name);
        $this->assertIdentitySlug($process->name);

        if ($workspace instanceof Workspace) {
            $this->assertIdentitySlug($workspace->name);
        }

        return ProcessRuntimeUnitName::for($app, $process, $workspace);
    }

    public function label(string $runtimeUnit): string
    {
        $unit = $this->assertUnitName($runtimeUnit);

        return "dev.hardimpact.orbit.{$unit}";
    }

    public function plistPath(string $runtimeUnit, Node $node): string
    {
        $label = $this->label($runtimeUnit);

        return $this->homeDirectory($node)."/Library/LaunchAgents/{$label}.plist";
    }

    public function stdoutLogPath(string $runtimeUnit, Node $node): string
    {
        return $this->homeDirectory($node)."/Library/Logs/Orbit/processes/{$runtimeUnit}.out.log";
    }

    public function stderrLogPath(string $runtimeUnit, Node $node): string
    {
        return $this->homeDirectory($node)."/Library/Logs/Orbit/processes/{$runtimeUnit}.err.log";
    }

    public function render(Node $node, App $app, Process $process, ?Workspace $workspace = null): string
    {
        $runtimeUnit = $this->unitName($app, $process, $workspace);
        $label = $this->label($runtimeUnit);
        $home = $this->homeDirectory($node);
        $workingDir = $this->workingDirectory($node, $app, $process, $workspace);
        $stdout = $this->stdoutLogPath($runtimeUnit, $node);
        $stderr = $this->stderrLogPath($runtimeUnit, $node);
        $keepAlive = $process->restart_policy !== \App\Enums\ProcessRestartPolicy::Never ? '<true/>' : '<false/>';

        $envLines = $this->environmentEntries($app, $node, $workspace, $home);

        $xml = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">',
            '<plist version="1.0">',
            '<dict>',
            '    <key>Label</key>',
            '    <string>'.$this->xml($label).'</string>',
            '    <key>ProgramArguments</key>',
            '    <array>',
            '        <string>/bin/bash</string>',
            '        <string>-lc</string>',
            '        <string>'.$this->xml($process->command).'</string>',
            '    </array>',
            '    <key>RunAtLoad</key>',
            '    <true/>',
            '    <key>KeepAlive</key>',
            "    {$keepAlive}",
            '    <key>WorkingDirectory</key>',
            '    <string>'.$this->xml($workingDir).'</string>',
            ...$envLines,
            '    <key>StandardOutPath</key>',
            '    <string>'.$this->xml($stdout).'</string>',
            '    <key>StandardErrorPath</key>',
            '    <string>'.$this->xml($stderr).'</string>',
            '</dict>',
            '</plist>',
        ];

        return implode(PHP_EOL, $xml).PHP_EOL;
    }

    private function workingDirectory(
        Node $node,
        App $app,
        Process $process,
        ?Workspace $workspace,
    ): string {
        if ($process->owner instanceof Node) {
            return $this->homeDirectory($node);
        }

        if ($workspace instanceof Workspace && $workspace->path !== '') {
            return $workspace->path;
        }

        if ($app->path !== '') {
            return $app->path;
        }

        return $this->homeDirectory($node);
    }

    /**
     * @return list<string>
     */
    private function environmentEntries(App $app, Node $node, ?Workspace $workspace, string $home): array
    {
        $environment =
            [
                'PATH' => "{$home}/.local/bin:{$home}/.vite-plus/bin:{$home}/.bun/bin:/opt/homebrew/bin:/opt/homebrew/sbin:/usr/local/bin:/usr/bin:/bin",
                'HOME' => $home,
            ] + $this->vite->shellVariables($app, $node, $workspace);

        $entries = [
            '    <key>EnvironmentVariables</key>',
            '    <dict>',
            '        <key>ORBIT_PROCESS</key>',
            '        <string>1</string>',
        ];

        foreach ($environment as $key => $value) {
            $entries[] = '        <key>'.$this->xml($key).'</key>';
            $entries[] = '        <string>'.$this->xml($value).'</string>';
        }

        $entries[] = '    </dict>';

        return $entries;
    }

    private function homeDirectory(Node $node): string
    {
        $user = is_string($node->user) && $node->user !== '' ? $node->user : 'orbit';

        if (NodeHostPaths::isMacosPlatform($node->platform)) {
            return $user === 'root' ? '/var/root' : "/Users/{$user}";
        }

        return $user === 'root' ? '/root' : "/home/{$user}";
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, flags: ENT_XML1 | ENT_QUOTES, encoding: 'UTF-8');
    }

    private function assertUnitName(string $name): string
    {
        if (! preg_match('/^[a-z0-9](?:[a-z0-9_.-]{0,62}[a-z0-9])?$/', $name)) {
            throw new InvalidArgumentException("Invalid launchd runtime unit name: {$name}");
        }

        return $name;
    }

    private function assertIdentitySlug(string $value): void
    {
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $value)) {
            throw new InvalidArgumentException("Invalid identity slug for launchd: {$value}");
        }
    }
}
