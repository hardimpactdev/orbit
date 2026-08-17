<?php

declare(strict_types=1);

use App\Data\Nodes\RoleSettings\AnalyticsRoleSettings;

it('rejects analytics settings without a valid postgres_process_id', function (mixed $processId): void {
    $settings = [
        'postgres_node_id' => 12,
        'clickhouse_node_id' => 13,
    ];

    if (func_num_args() === 1) {
        $settings['postgres_process_id'] = $processId;
    }

    expect(fn () => AnalyticsRoleSettings::fromArray($settings))
        ->toThrow(InvalidArgumentException::class, 'The analytics role requires a valid postgres_process_id.');
})->with([
    'missing' => [null],
    'zero' => [0],
    'negative' => [-1],
    'string' => ['120'],
]);

it('rejects analytics settings that omit postgres_process_id entirely', function (): void {
    expect(fn () => AnalyticsRoleSettings::fromArray([
        'postgres_node_id' => 12,
        'clickhouse_node_id' => 13,
    ]))
        ->toThrow(InvalidArgumentException::class, 'The analytics role requires a valid postgres_process_id.');
});
