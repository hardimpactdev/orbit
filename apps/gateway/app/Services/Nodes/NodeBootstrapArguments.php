<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use Illuminate\Http\Request;

final readonly class NodeBootstrapArguments
{
    /**
     * @return array<string, mixed>
     */
    public function fromRequest(Request $request): array
    {
        $arguments = [
            'name' => $this->optionalString($request, 'name'),
            '--json' => true,
        ];

        foreach ([
            '--template' => 'template',
            '--host' => 'host',
            '--ingress' => 'ingress_node',
            '--tld' => 'tld',
            '--redis-node' => 'redis_node',
            '--postgres-node' => 'postgres_node',
            '--clickhouse-node' => 'clickhouse_node',
            '--s3-data-path' => 's3_data_path',
            '--user' => 'user',
            '--gateway-endpoint' => 'gateway_endpoint',
            '--platform' => 'platform',
            '--architecture' => 'architecture',
            '--self-grant' => 'self_grant',
            '--self-grant-permissions' => 'self_grant_permissions',
            '--grant-to-preset' => 'grant_to_preset',
            '--grant-to-permissions' => 'grant_to_permissions',
            '--grant-from-preset' => 'grant_from_preset',
            '--grant-from-permissions' => 'grant_from_permissions',
        ] as $option => $key) {
            $this->addStringOption($arguments, $option, $request, $key);
        }

        $roles = $this->stringList($request, 'roles');

        if ($roles !== []) {
            $arguments['--roles'] = implode(',', $roles);
        }

        foreach ([
            '--grant-to' => 'grant_to',
            '--grant-from' => 'grant_from',
            '--agent-tool' => 'agent_tools',
        ] as $option => $key) {
            $values = $this->stringList($request, $key);

            if ($values !== []) {
                $arguments[$option] = $values;
            }
        }

        return $arguments;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function addStringOption(array &$arguments, string $option, Request $request, string $key): void
    {
        $value = $this->optionalString($request, $key);

        if ($value !== null) {
            $arguments[$option] = $value;
        }
    }

    /**
     * @return list<string>
     */
    private function stringList(Request $request, string $key): array
    {
        /** @var mixed $value */
        $value = $request->input($key);

        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    private function optionalString(Request $request, string $key): ?string
    {
        /** @var mixed $value */
        $value = $request->input($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
