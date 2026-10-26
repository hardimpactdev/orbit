<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\Gateway\GatewayServingHost;
use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Gateway\GatewayHorizon;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Facades\Config;
use Throwable;

/** Observes identity only. The root operator, never this observer, attests that a Process is unrelated. */
final readonly class GatewayReleaseProcessDeclaration
{
    public function __construct(
        private AgentCommandRunner $agents,
        private GatewayReleaseLayout $layout,
        private GatewayServingHost $servingHost,
    ) {}

    /** @return array{process_id: int, node_id: int, unit: string, source_sha: string, target_sha: string, spec_sha256: string, unit_sha256: string, runtime_sha256: string} */
    public function observe(string $targetSha, int $id): array
    {
        try {
            return $this->inspect($targetSha, $id);
        } catch (GatewayReleaseException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->refuse('An unrelated Process identity could not be read. Keep the hold and inspect locally.');
        }
    }

    /** @return array{process_id: int, node_id: int, unit: string, source_sha: string, target_sha: string, spec_sha256: string, unit_sha256: string, runtime_sha256: string} */
    private function inspect(string $targetSha, int $id): array
    {
        if (! GatewayReleaseCommit::isSha($targetSha)) {
            $this->refuse('A Process declaration requires the exact target SHA.');
        }
        $process = Process::query()->find($id);
        if (! $process instanceof Process || $process->owner_type !== Node::class
            || $process->runtime !== ProcessRuntime::Systemd || $process->retired_runtime !== null
            || $process->source_definition_id !== null || $process->systemd_activation_pending
            || $process->status !== LifecycleStatus::Active || $process->desired_state !== DesiredProcessState::Running) {
            $this->refuse('Only an active, settled Node systemd Process can be declared unrelated.');
        }
        $target = new ProcessTargetResolver()->forInspection($process);
        $node = $target->node;
        if (! $this->servingHost->is($node)) {
            $this->refuse('The Process placement must identify this remembered serving Gateway Node.');
        }
        $config = $process->runtime_config;
        $command = $config['command'] ?? null;
        if (array_diff(array_keys($config), ['command', 'environment', 'user']) !== []
            || ($config['environment'] ?? []) !== [] || ! is_array($command) || ! array_is_list($command)
            || $command === [] || ! array_all($command, static fn (mixed $argument): bool => is_string($argument))) {
            $this->refuse('A declaration requires a plain Node Process command without presets or environment dependencies.');
        }
        if (GatewayHorizon::runs($process)) {
            $this->refuse('A Gateway consumer cannot be declared unrelated.');
        }
        $renderer = new SystemdProcessRenderer;
        $unit = $renderer->unitName($process);
        $rendered = $renderer->render($process, $target);
        $unitHash = hash('sha256', $rendered);
        if ($process->systemd_activation_hash !== null && $process->systemd_activation_hash !== $unitHash) {
            $this->refuse('The Process activation specification differs from its current record.');
        }
        $observed = $this->agents->run($node, 'gateway.inspect-release-process', [
            'checkout' => $this->layout->applicationPath, 'orbit_home' => Config::string('orbit.home'),
            'target_sha' => $targetSha, 'process_id' => $process->id, 'unit' => $unit,
            'user' => $config['user'] ?? $target->user, 'directory' => $process->working_directory,
            'command' => $command, 'rendered' => $rendered,
        ]);
        $sourceSha = $observed['source_sha'] ?? null;
        $runtimeHash = $observed['runtime_sha256'] ?? null;
        if (! is_string($sourceSha) || ! GatewayReleaseCommit::isSha($sourceSha)
            || ($observed['unit_sha256'] ?? null) !== $unitHash
            || ! is_string($runtimeHash) || preg_match('/\A[a-f0-9]{64}\z/D', $runtimeHash) !== 1) {
            $this->refuse('The agent returned an incomplete Process identity.');
        }

        return [
            'process_id' => $process->id, 'node_id' => $node->id, 'unit' => $unit,
            'source_sha' => $sourceSha, 'target_sha' => $targetSha,
            'spec_sha256' => $this->hash([
                'process' => $process->only(['id', 'name', 'owner_type', 'owner_id', 'source_definition_id', 'runtime', 'retired_runtime',
                    'working_directory', 'runtime_config', 'restart_policy', 'desired_state', 'status', 'keep_alive', 'restart_on_deploy',
                    'systemd_activation_hash', 'systemd_activation_pending']),
                'node' => $node->only(['id', 'platform', 'user', 'status']),
            ]),
            'unit_sha256' => $unitHash,
            'runtime_sha256' => $runtimeHash,
        ];
    }

    /** @param array<mixed> $values */
    private function hash(array $values): string
    {
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function refuse(string $message): never
    {
        throw new GatewayReleaseException('drain', 'gateway.release_not_drained', $message);
    }
}
