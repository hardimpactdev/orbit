<?php

declare(strict_types=1);

namespace App\Services\Convergence;

use App\Contracts\RemoteShell;
use App\Data\Convergence\ConvergenceApplyResult;
use App\Data\Convergence\SystemdServicePlan;
use App\Data\Convergence\SystemdServiceProbe;
use App\Enums\Convergence\ConvergenceStatus;
use App\Models\Node;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\RemoteShell\RemoteShellSuccessData;
use InvalidArgumentException;
use JsonException;

final readonly class SystemdService
{
    public function __construct(
        public string $unitName,
        public string $content,
        public bool $enabled = true,
    ) {
        $this->serviceName();
    }

    public function probe(Node $node, RemoteShell $remoteShell): SystemdServiceProbe
    {
        $result = $this->localExecutor()->runInternal(
            node: $node,
            commandName: 'internal:process-systemd-service',
            arguments: ['probe', $this->serviceName()],
            transportOptions: [
                'metadata' => [
                    'ORBIT_OPERATION_ID' => 'systemd-service.probe',
                ],
                'timeout' => 30,
                'throw' => false,
            ],
        );

        if (! $result->successful()) {
            return new SystemdServiceProbe(
                reachable: false,
                exists: false,
                enabled: false,
                error: trim($result->stderr) !== ''
                    ? trim($result->stderr)
                    : "Probe exited with code {$result->exitCode}.",
            );
        }

        try {
            $payload = RemoteShellSuccessData::fromJsonEnvelope($result);
        } catch (JsonException $exception) {
            return new SystemdServiceProbe(
                reachable: false,
                exists: false,
                enabled: false,
                error: "Probe returned invalid JSON: {$exception->getMessage()}",
            );
        }

        return new SystemdServiceProbe(
            reachable: true,
            exists: ($payload['exists'] ?? null) === true,
            enabled: ($payload['enabled'] ?? null) === true,
            hash: is_string($payload['hash'] ?? null) ? $payload['hash'] : null,
        );
    }

    public function plan(SystemdServiceProbe $probe): SystemdServicePlan
    {
        if (! $probe->reachable) {
            return new SystemdServicePlan(
                status: ConvergenceStatus::Unreachable,
                summary: "Could not inspect systemd service {$this->serviceName()}.",
                details: $this->details(['error' => $probe->error]),
            );
        }

        if (! $probe->exists) {
            return new SystemdServicePlan(
                status: ConvergenceStatus::Changed,
                summary: "Install systemd service {$this->serviceName()}.",
                details: $this->details([
                    'observed_hash' => null,
                    'observed_enabled' => $probe->enabled,
                ]),
            );
        }

        if (! hash_equals($this->hash(), $probe->hash ?? '')) {
            return new SystemdServicePlan(
                status: ConvergenceStatus::Changed,
                summary: "Update systemd service {$this->serviceName()}.",
                details: $this->details([
                    'observed_hash' => $probe->hash,
                    'observed_enabled' => $probe->enabled,
                ]),
            );
        }

        if ($this->enabled && ! $probe->enabled) {
            return new SystemdServicePlan(
                status: ConvergenceStatus::Changed,
                summary: "Enable systemd service {$this->serviceName()}.",
                details: $this->details([
                    'observed_hash' => $probe->hash,
                    'observed_enabled' => $probe->enabled,
                ]),
            );
        }

        if (! $this->enabled && $probe->enabled) {
            return new SystemdServicePlan(
                status: ConvergenceStatus::Changed,
                summary: "Disable systemd service {$this->serviceName()}.",
                details: $this->details([
                    'observed_hash' => $probe->hash,
                    'observed_enabled' => $probe->enabled,
                ]),
            );
        }

        return new SystemdServicePlan(
            status: ConvergenceStatus::Ok,
            summary: "Systemd service {$this->serviceName()} already matches gateway intent.",
            details: $this->details([
                'observed_hash' => $probe->hash,
                'observed_enabled' => $probe->enabled,
            ]),
        );
    }

    public function apply(Node $node, RemoteShell $remoteShell, SystemdServicePlan $plan): ConvergenceApplyResult
    {
        if (! $plan->shouldApply()) {
            return new ConvergenceApplyResult(
                status: $plan->status,
                summary: $plan->summary,
                details: $plan->details,
            );
        }

        $result = $this->localExecutor()->runInternal(
            node: $node,
            commandName: 'internal:process-systemd-service',
            arguments: ['apply', $this->serviceName()],
            transportOptions: [
                'input' => json_encode([
                    'content' => $this->content,
                    'enabled' => $this->enabled,
                ], JSON_THROW_ON_ERROR),
                'metadata' => [
                    'ORBIT_OPERATION_ID' => 'systemd-service.apply',
                ],
                'timeout' => 30,
                'throw' => false,
            ],
        );

        if (! $result->successful()) {
            return new ConvergenceApplyResult(
                status: ConvergenceStatus::Failed,
                summary: "Failed to apply systemd service {$this->serviceName()}.",
                details: $this->details([
                    'exit_code' => $result->exitCode,
                    'error' => trim($result->stderr) !== '' ? trim($result->stderr) : null,
                ]),
            );
        }

        return new ConvergenceApplyResult(
            status: ConvergenceStatus::Changed,
            summary: "Applied systemd service {$this->serviceName()}.",
            details: $this->details(),
        );
    }

    public function serviceName(): string
    {
        $serviceName = str_ends_with($this->unitName, '.service') ? $this->unitName : "{$this->unitName}.service";

        if (preg_match('/^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?\.service$/', $serviceName) === 1) {
            return $serviceName;
        }

        throw new InvalidArgumentException("Unsafe systemd service name: {$serviceName}");
    }

    public function unitPath(): string
    {
        return '/etc/systemd/system/'.$this->serviceName();
    }

    public function hash(): string
    {
        return hash('sha256', $this->content);
    }

    private function localExecutor(): RemoteLocalExecutor
    {
        return app(RemoteLocalExecutor::class);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function details(array $extra = []): array
    {
        return [
            'service' => $this->serviceName(),
            'path' => $this->unitPath(),
            'enabled' => $this->enabled,
            'expected_hash' => $this->hash(),
            ...$extra,
        ];
    }
}
