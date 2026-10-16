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
    public string $vpVersion = '0.3.3';

    public bool $installed = false;

    public bool $installFails = false;

    public bool $inspectionFails = false;

    public bool $installIncomplete = false;

    public bool $buildFails = false;

    /** @var list<string>|null */
    public ?array $managedRuntimes = null;

    /** @var list<string> */
    public array $removedReleases = [];

    /** @var list<string> */
    public array $busyReleases = [];

    /** @var list<RemoteCommand> */
    public array $commands = [];

    /** @var list<SshConnection> */
    public array $connections = [];

    /** @var list<string> */
    public array $operations = [];

    /** @var list<array<string, mixed>> */
    public array $inputs = [];

    /** @var array<string, mixed> */
    public array $configuration = [
        'package' => ['devDependencies' => ['vite-plus' => '^0.3.0'], 'packageManager' => 'npm@11.12.0', 'engines' => ['node' => '24.22.0']],
        'locks' => ['package-lock.json' => '{"lockfileVersion":3,"packages":{"node_modules/vite-plus":{"version":"0.3.3"}}}'],
        '.node-version' => '24.22.0', '.nvmrc' => null, 'npmrc' => '',
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
        } elseif (in_array('tools.inspect-vp-store', $arguments, true)) {
            $operation = 'inspect';
            $input = json_decode(explode("\0", $command->input)[1], true, flags: JSON_THROW_ON_ERROR);
            $this->inputs[] = $input;
            $exit = $this->inspectionFails ? 1 : 0;
            $output = json_encode(['outcome' => 'inspected', 'data' => ['installed' => array_map(fn (array $environment): bool => $this->managedRuntimes === null ? $this->installed
                : array_diff($this->runtimePaths($environment), $this->managedRuntimes) === [], $input['environments'])]], JSON_THROW_ON_ERROR);
        } elseif (in_array('tools.install-vp-environment', $arguments, true)) {
            $operation = 'install';
            ($this->onInstall ?? static function (): void {})();
            $this->inputs[] = json_decode(explode("\0", $command->input)[1], true, flags: JSON_THROW_ON_ERROR);
            $exit = $this->installFails ? 1 : 0;
            if (! $this->installFails && ! $this->installIncomplete) {
                $this->installed = true;
                if ($this->managedRuntimes !== null) {
                    $this->managedRuntimes = array_values(array_unique([...$this->managedRuntimes, ...$this->runtimePaths($this->inputs[array_key_last($this->inputs)])]));
                }
            }
            $output = '{"outcome":"installed","data":{"verified":{}}}';
        } elseif (in_array('tools.prune-vp-store', $arguments, true)) {
            $operation = 'prune';
            $input = json_decode(explode("\0", $command->input)[1], true, flags: JSON_THROW_ON_ERROR);
            $this->inputs[] = $input;
            $removed = [];
            if ($this->managedRuntimes !== null) {
                $keep = [];
                foreach ($input['references'] as $reference) {
                    $keep = [...$keep, ...$this->runtimePaths($reference)];
                }
                $removed = array_values(array_diff($this->managedRuntimes, $keep));
                $this->managedRuntimes = array_values(array_intersect($this->managedRuntimes, $keep));
            }
            $output = json_encode(['outcome' => 'pruned', 'data' => ['removed' => $removed]], JSON_THROW_ON_ERROR);
        } elseif (str_contains($command->input ?? '', 'expected_selected=$7')) {
            $operation = 'release-prune';
            foreach ($this->removedReleases as $name) {
                $output .= "PRUNED\t{$name}\n";
            }
            foreach ($this->busyReleases as $name) {
                $output .= "IN_USE\t{$name}\n";
            }
        } elseif ($command->protectedInput !== null) {
            $operation = 'build';
            $exit = $this->buildFails ? 1 : 0;
        } else {
            throw new LogicException('Unexpected vp store SSH command: '.$text);
        }
        $this->operations[] = $operation;

        return new CommandResult($exit, $output, $exit === 0 ? '' : 'fixture command failed', 1, false);
    }

    /** @param array<string, mixed> $environment
     * @return list<string>
     */
    private function runtimePaths(array $environment): array
    {
        return ['js_runtime/node/'.$environment['node_version'],
            'package_manager/'.$environment['package_manager'].'/'.$environment['package_manager_version']];
    }
}
