<?php

declare(strict_types=1);

namespace App\Data\Nodes\RoleSettings;

use InvalidArgumentException;

final readonly class AnalyticsRoleSettings implements NodeRoleSettings
{
    public function __construct(
        public int $postgresNodeId,
        public int $postgresProcessId,
        public int $clickhouseNodeId,
    ) {
        if ($postgresNodeId < 1 || $postgresProcessId < 1 || $clickhouseNodeId < 1) {
            throw new InvalidArgumentException(
                'The analytics role requires valid postgres_node_id, postgres_process_id, and clickhouse_node_id settings.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function fromArray(array $settings): self
    {
        $unknownKeys = array_diff(
            array_keys($settings),
            ['postgres_node_id', 'postgres_process_id', 'clickhouse_node_id'],
        );

        if ($unknownKeys !== []) {
            throw new InvalidArgumentException('The analytics role does not accept unknown settings.');
        }

        if (
            ! is_int($settings['postgres_node_id'] ?? null)
            || $settings['postgres_node_id'] < 1
            || ! is_int($settings['clickhouse_node_id'] ?? null)
            || $settings['clickhouse_node_id'] < 1
        ) {
            throw new InvalidArgumentException(
                'The analytics role requires valid postgres_node_id and clickhouse_node_id settings.',
            );
        }

        if (
            ! is_int($settings['postgres_process_id'] ?? null)
            || $settings['postgres_process_id'] < 1
        ) {
            throw new InvalidArgumentException(
                'The analytics role requires a valid postgres_process_id.',
            );
        }

        return new self(
            postgresNodeId: $settings['postgres_node_id'],
            postgresProcessId: $settings['postgres_process_id'],
            clickhouseNodeId: $settings['clickhouse_node_id'],
        );
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'postgres_node_id' => $this->postgresNodeId,
            'postgres_process_id' => $this->postgresProcessId,
            'clickhouse_node_id' => $this->clickhouseNodeId,
        ];
    }
}
