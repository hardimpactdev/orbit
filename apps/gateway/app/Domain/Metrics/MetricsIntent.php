<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Integrations\IntegrationIntent;
use App\Models\Operation;
use App\Support\ValidatedData;
use InvalidArgumentException;

/** @phpstan-type Candidate array{node_id: int, name: string, address: string, selected: bool, caddy: bool, fpm: bool, hosts: list<string>, instance_ids: list<int>} */
final readonly class MetricsIntent
{
    /** @return list<Candidate> */
    public static function fleet(Operation $operation, string $field = 'fleet'): array
    {
        $raw = $operation->input[$field];
        if (! is_array($raw) || ! array_is_list($raw)) {
            throw new InvalidArgumentException('The metrics projection must be a list.');
        }
        $nodes = [];
        foreach ($raw as $value) {
            $node = ValidatedData::object($value);
            $nodes[] = ['node_id' => ValidatedData::integer($node['node_id']), 'name' => ValidatedData::string($node['name']), 'address' => ValidatedData::string($node['address']),
                'hosts' => ValidatedData::stringList($node['hosts']), 'selected' => IntegrationIntent::boolean($node['selected']), 'caddy' => IntegrationIntent::boolean($node['caddy']), 'fpm' => IntegrationIntent::boolean($node['fpm']), 'instance_ids' => ValidatedData::integerList($node['instance_ids'])];
        }

        return $nodes;
    }

    /** @return list<array{name: string, address: string}> */
    public static function targets(Operation $operation): array
    {
        $raw = $operation->input['targets'];
        if (! is_array($raw) || ! array_is_list($raw)) {
            throw new InvalidArgumentException('The metrics targets must be a list.');
        }
        $targets = [];
        foreach ($raw as $value) {
            $target = ValidatedData::object($value);
            $targets[] = ['name' => ValidatedData::string($target['name']), 'address' => ValidatedData::string($target['address'])];
        }

        return $targets;
    }

    public static function nodeId(Operation $operation): int
    {
        return ValidatedData::integer($operation->node_id);
    }

    public static function integer(Operation $operation, string $key): int
    {
        return ValidatedData::integer($operation->input[$key]);
    }

    public static function flag(Operation $operation, string $key): bool
    {
        return IntegrationIntent::boolean($operation->input[$key]);
    }
}
