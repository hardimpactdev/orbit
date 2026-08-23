<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\Node;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use App\Services\RemoteShell\RunsInternalCommands;

final readonly class FleetUpdateAgentVerifier
{
    public function __construct(
        private FleetUpdateTargetSelector $targets,
        private ?RunsInternalCommands $localExecutor = null,
    ) {}

    public function verify(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        $skips = app(FleetUpdatePreMutationSkipRegistry::class);

        foreach ($this->nodes($operationRun) as $node) {
            if ($skips->skipped($operationRun->id, $node->name)) {
                continue;
            }

            $artifact = $this->artifact($plan, CliArtifactPlatform::forNode($node));

            if ($artifact === null) {
                continue;
            }

            $result = $this->localExecutor()->runInternal(
                node: $node,
                commandName: 'internal:fleet-update:verify',
                arguments: ['agent'],
                transportOptions: [
                    'input' => json_encode([
                        'bin_path' => FleetUpdateNodeAgentBinary::binPath($node),
                        'sha256' => $artifact['sha256'],
                    ], JSON_THROW_ON_ERROR),
                    'timeout' => 30,
                    'metadata' => [
                        'ORBIT_OPERATION_ID' => $operationRun->id,
                    ],
                ],
            );

            if (! $result->successful()) {
                throw new FleetUpdateVerificationFailed(
                    'agent_verification_failed',
                    'Orbit Agent verification failed.',
                );
            }
        }
    }

    /**
     * @return list<Node>
     */
    private function nodes(OperationRun $operationRun): array
    {
        $nodes = [];

        foreach ($this->targets->workloadNodes($operationRun) as $node) {
            $nodes[$node->name] = $node;
        }

        return array_values($nodes);
    }

    /**
     * @return array{sha256: string}|null
     */
    private function artifact(OperationUpdatePlan $plan, string $platform): ?array
    {
        $artifact = (($plan->agent_artifacts ?? []))[$platform] ?? null;

        if ($artifact === null) {
            return null;
        }

        if (! is_array($artifact) || ! is_string($artifact['sha256'] ?? null)) {
            throw new FleetUpdateVerificationFailed(
                'agent_verification_failed',
                'Orbit Agent verification failed.',
            );
        }

        return [
            'sha256' => strtolower($artifact['sha256']),
        ];
    }

    private function localExecutor(): RunsInternalCommands
    {
        return $this->localExecutor ?? app(RunsInternalCommands::class);
    }
}
