<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Fleet\AgentToolsContribution;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Processes\ProcessRuntime;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;

/**
 * Names the model router for the daily agent tool update (ADR 0255, [Upgrade the router](/reference/ai-router#upgrade-the-router)):
 * the CLIProxyAPI Process on the router's Node, installed as `<folder>/releases/<version>` with a `current` link that its
 * command runs. The run installs the latest upstream release beside the others, flips the link, restarts the Process through
 * the Process restart workflow and checks one request; a failed check flips the link back.
 */
final readonly class AiRouterAgentTools implements AgentToolsContribution
{
    public const string Repository = 'router-for-me/CLIProxyAPI';

    /** How many releases a run keeps besides the current one. */
    public const int Keep = 2;

    private const string Executable = '/current/cli-proxy-api';

    public function __construct(private ExtensionStore $extensions, private AiRouterState $state, private ProcessOperationAdmission $processes,
        private SwampSshExecutionInput $ssh) {}

    public function hosts(): array
    {
        return [];
    }

    public function router(): ?array
    {
        $nodeId = $this->state->nodeId();
        $node = $nodeId === null ? null : Node::query()->find($nodeId);
        if (! $this->extensions->enabled('ai-router') || ! $this->state->enabled() || ! $node instanceof Node) {
            return null;
        }
        $process = $this->process($node);
        $asset = match ($node->architecture) {
            'x86_64', 'amd64' => 'linux_amd64',
            'arm64', 'aarch64' => 'linux_aarch64',
            default => null,
        };
        $ssh = $this->ssh->forNode($node);
        if ($process === null || $asset === null || $ssh['target']['host_keys'] === null) {
            return null;
        }
        [$process, $command] = $process;
        $executable = (string) array_find($command, static fn (string $part): bool => str_ends_with($part, self::Executable));
        $flag = array_search('-config', $command, true);
        $config = is_int($flag) ? ($command[$flag + 1] ?? null) : null;
        if (! is_string($config) || ! str_starts_with($config, '/')) {
            return null;
        }
        $restart = Arr::only($this->processes->inputFor($process, 'restart'), ['process_id', 'runtime', 'name', 'image', 'on_demand', 'directory', 'vite']);

        return ['node_id' => $node->id, 'name' => $node->name, 'host' => $ssh['target']['host'], 'user' => $ssh['target']['user'],
            'port' => $ssh['target']['port'], 'host_keys' => $ssh['target']['host_keys'], 'identity_file' => $ssh['identity_file'],
            'process_id' => $process->id, 'folder' => substr($executable, 0, -strlen(self::Executable)),
            'config' => $config,
            'repository' => self::Repository, 'asset' => $asset, 'keep' => self::Keep, 'model' => Config::string('fleet.agent_tools_router_model'),
            'restart' => ['control' => $restart, 'ssh_execution' => $ssh]];
    }

    /**
     * The one systemd Process on the router's Node whose command runs `<folder>/current/cli-proxy-api`, or null. Its `-config`
     * argument names the configuration the check reads its client key and port from; a router without one is not updated.
     *
     * @return array{0: Process, 1: list<string>}|null
     */
    private function process(Node $node): ?array
    {
        $found = [];
        foreach (Process::query()->where('owner_type', Node::class)->where('owner_id', $node->id)->where('runtime', ProcessRuntime::Systemd)->get() as $process) {
            $command = $process->runtime_config['command'] ?? null;
            if (! is_array($command) || ! array_is_list($command)) {
                continue;
            }
            $command = array_values(array_filter($command, is_string(...)));
            if (array_any($command, static fn (string $part): bool => str_starts_with($part, '/') && str_ends_with($part, self::Executable)
                && strlen($part) > strlen(self::Executable) + 1)) {
                $found[] = [$process, $command];
            }
        }

        return count($found) === 1 ? $found[0] : null;
    }
}
