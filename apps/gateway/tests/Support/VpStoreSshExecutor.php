<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use Closure;
use LogicException;

final class VpStoreSshExecutor implements SshExecutor
{
    public string $vpVersion = '0.3.0';

    public bool $installed = false;

    public bool $installFails = false;

    public bool $updateFails = false;

    public bool $inspectionFails = false;

    public bool $installIncomplete = false;

    public string $nodeVersion = '24.22.0';

    public string $packageManager = 'npm';

    public string $packageManagerVersion = '11.12.0';

    /** @var list<RemoteCommand> */
    public array $commands = [];

    /** @var list<SshConnection> */
    public array $connections = [];

    /** @var list<string> */
    public array $operations = [];

    /** @var array<string, mixed> */
    public array $configuration = [
        'package' => [
            'devDependencies' => ['vite-plus' => '0.3.3'],
            'packageManager' => 'npm@11.12.0',
            'engines' => ['node' => '24.22.0'],
            'scripts' => ['preinstall' => 'must-not-run-as-owner'],
        ],
        '.node-version' => '24.22.0',
        '.nvmrc' => null,
    ];

    public ?Closure $onInstall = null;

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->commands[] = $command;
        $this->connections[] = $connection;
        $arguments = $command->arguments;
        $text = implode("\n", $arguments);
        $exit = 0;
        $output = '';
        if ($arguments[0] === '/bin/bash') {
            $operation = 'scope';
            $output = "/opt/orbit/vite-plus/bin/vp\n";
        } elseif (in_array('tools.read-vp-project', $arguments, true)) {
            $operation = 'project';
            $output = json_encode(['outcome' => 'read', 'data' => $this->configuration], JSON_THROW_ON_ERROR)."\n";
        } elseif (in_array('--version', $arguments, true)) {
            $operation = 'vp-version';
            $output = 'vp v'.$this->vpVersion."\n";
        } elseif (in_array('upgrade', $arguments, true)) {
            $operation = 'vp-upgrade';
            $exit = $this->updateFails ? 1 : 0;
            if (! $this->updateFails) {
                $this->vpVersion = $arguments[array_search('upgrade', $arguments, true) + 1];
            }
        } elseif (str_contains($text, 'TemporaryDirectory')) {
            $operation = 'resolve';
            $output = json_encode([
                'node' => ['version' => $this->nodeVersion, 'mode' => 'managed', 'installed' => $this->installed],
                'package_manager' => ['name' => $this->packageManager, 'version' => $this->packageManagerVersion, 'mode' => 'managed', 'installed' => $this->installed],
            ], JSON_THROW_ON_ERROR);
        } elseif (str_contains($text, 'inventory = json.loads')) {
            $operation = 'inspect';
            $exit = $this->inspectionFails ? 1 : 0;
            $output = json_encode($this->installed);
        } elseif (in_array('install', $arguments, true)) {
            $operation = 'install';
            ($this->onInstall ?? static function (): void {})();
            $exit = $this->installFails ? 1 : 0;
            if (! $this->installFails && ! $this->installIncomplete) {
                $this->installed = true;
            }
        } elseif ($command->protectedInput !== null) {
            $operation = 'build';
        } else {
            throw new LogicException('Unexpected vp store SSH command: '.$text);
        }
        $this->operations[] = $operation;

        return new CommandResult($exit, $output, $exit === 0 ? '' : 'fixture download denied', 1, false);
    }
}
