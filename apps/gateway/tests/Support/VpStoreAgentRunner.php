<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\AgentCommands\AgentBinaryInput;
use App\Infrastructure\AgentCommands\AgentBinaryOutput;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;
use Closure;
use LogicException;

final class VpStoreAgentRunner implements AgentCommandRunner
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

    /** @var list<array{command:string,input:array<string,mixed>}> */
    public array $agentCalls = [];

    /** @var list<?float> */
    public array $deadlines = [];

    public function install(Node $node): bool
    {
        throw new LogicException('Unexpected agent installation');
    }

    public function run(Node $node, string $command, array $input = [], ?float $deadlineSeconds = null, ?AgentBinaryOutput $output = null, ?AgentBinaryInput $binaryInput = null): array
    {
        $this->deadlines[] = $deadlineSeconds;
        $this->agentCalls[] = ['command' => $command, 'input' => $input];
        if ($command === 'tools.vp-version') {
            $this->operations[] = 'vp-version';

            return ['version' => 'vp v'.$this->vpVersion];
        }
        if ($command === 'tools.inspect-vp-scope') {
            $this->operations[] = 'scope';

            return ['binary' => '/opt/orbit/vite-plus/bin/vp'];
        }
        if ($command === 'tools.read-vp-project') {
            $this->operations[] = 'project';

            return $this->configuration;
        }
        if ($command === 'tools.inspect-vp-store') {
            $this->operations[] = 'inspect';
            $this->inputs[] = $input;
            if ($this->inspectionFails) {
                throw new AgentCommandRefused($command, 'tools.store_invalid', 'Fixture inspection failed');
            }

            return ['installed' => array_map(fn (array $environment): bool => $this->managedRuntimes === null ? $this->installed
                : array_diff($this->runtimePaths($environment), $this->managedRuntimes) === [], $input['environments'])];
        }
        if ($command === 'tools.install-vp-environment') {
            $this->operations[] = 'install';
            ($this->onInstall ?? static function (): void {})();
            $this->inputs[] = [...$input['environment'], 'npmrc' => $input['npmrc']];
            if ($this->installFails) {
                throw new AgentCommandRefused($command, 'tools.store_failed', 'Fixture installation failed');
            }
            if (! $this->installIncomplete) {
                $this->installed = true;
                if ($this->managedRuntimes !== null) {
                    $this->managedRuntimes = array_values(array_unique([...$this->managedRuntimes, ...$this->runtimePaths($input['environment'])]));
                }
            }

            return ['verified' => []];
        }
        if ($command === 'tools.prune-vp-store') {
            $this->operations[] = 'prune';
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

            return ['removed' => $removed];
        }
        throw new LogicException('Unexpected store agent command: '.$command);
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
