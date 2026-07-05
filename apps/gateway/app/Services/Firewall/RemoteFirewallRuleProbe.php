<?php

declare(strict_types=1);

namespace App\Services\Firewall;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\RemoteShell\RemoteLocalExecutor;
use JsonException;

final readonly class RemoteFirewallRuleProbe
{
    public function __construct(
        private RemoteLocalExecutor $localExecutor,
    ) {}

    public function output(Node $node): RemoteShellResult
    {
        return $this->localExecutor->runInternal(
            node: $node,
            commandName: 'internal:firewall-rule:probe',
            transportOptions: [
                'metadata' => [
                    'ORBIT_OPERATION_ID' => 'firewall.rule.probe',
                ],
                'timeout' => 15,
            ],
        );
    }

    public function outputIfAvailable(Node $node): RemoteShellResult
    {
        return $this->localExecutor->runInternal(
            node: $node,
            commandName: 'internal:firewall-rule:probe',
            transportOptions: [
                'metadata' => [
                    'ORBIT_OPERATION_ID' => 'firewall.rule.probe',
                ],
                'throw' => false,
                'timeout' => 15,
            ],
        );
    }

    public function extractOutput(RemoteShellResult $result): string
    {
        $data = $this->successData($result->stdout);

        return is_string($data['output'] ?? null) ? $data['output'] : $result->stdout;
    }

    /**
     * @return array<string, mixed>
     */
    private function successData(string $output): array
    {
        try {
            /** @var mixed $payload */
            $payload = json_decode(trim($output), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($payload)) {
            return [];
        }

        /** @var mixed $success */
        $success = $payload['success'] ?? null;

        if (! is_array($success)) {
            return [];
        }

        /** @var mixed $data */
        $data = $success['data'] ?? null;

        if (! is_array($data)) {
            return [];
        }

        foreach (array_keys($data) as $key) {
            if (! is_string($key)) {
                return [];
            }
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
