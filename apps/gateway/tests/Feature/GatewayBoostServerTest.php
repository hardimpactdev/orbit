<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * @param  list<string>  $command
 */
function productionArtisan(array $command, string $input = ''): Process
{
    $process = new Process(
        ['php', base_path('artisan'), ...$command],
        base_path(),
        ['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'CACHE_STORE' => 'file', 'DB_DATABASE' => ':memory:'],
        $input,
        30,
    );
    $process->run();

    return $process;
}

it('starts the Boost MCP server in a production Gateway with only the tools the Gateway can use and no guidance prompts', function (): void {
    $requests = collect([
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']]],
        ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
        ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
        ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'prompts/list'],
    ])->map(fn (array $request): string => json_encode($request, JSON_THROW_ON_ERROR)."\n")->implode('');

    $process = productionArtisan(['boost:mcp'], $requests);

    $responses = collect(explode("\n", trim($process->getOutput())))
        ->map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR));
    $tools = $responses->firstWhere('id', 2)['result']['tools'];

    expect(collect($tools)->pluck('name')->sort()->values()->all())->toBe([
        'database-connections',
        'database-query',
        'database-schema',
        'last-error',
        'read-log-entries',
        'search-docs',
    ])->and($responses->firstWhere('id', 3)['result']['prompts'])->toBe([]);
});

it('loads no Boost for any other artisan command', function (): void {
    $process = productionArtisan(['list', '--format=json']);

    $names = collect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['commands'])->pluck('name');

    expect($process->isSuccessful())->toBeTrue()
        ->and($names->filter(fn (string $name): bool => str_starts_with($name, 'boost:'))->all())->toBe([]);
});
