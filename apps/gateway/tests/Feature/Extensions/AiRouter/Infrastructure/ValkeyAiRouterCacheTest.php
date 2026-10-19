<?php

declare(strict_types=1);

use App\Models\DatabaseConnection;
use Orbit\Extensions\AiRouter\Infrastructure\ValkeyAiRouterCache;
use Predis\Client;

it('skips repeated scan keys and finishes remaining keys after a lost connection', function (): void {
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('executeRaw')->once()->with(['SCAN', '0', 'MATCH', 'orbit:proxycli:*', 'COUNT', '100'], Mockery::any())->ordered()->andReturn(['17', ['orbit:proxycli:a', 'orbit:proxycli:a']]);
    $client->shouldReceive('executeRaw')->once()->with(['EXISTS', 'orbit:proxycli:a'], Mockery::any())->ordered()->andReturn(1);
    $client->shouldReceive('executeRaw')->once()->with(['RENAME', 'orbit:proxycli:a', 'orbit:ai-router:a'], Mockery::any())->ordered()->andReturn('OK');
    $client->shouldReceive('executeRaw')->once()->with(['EXISTS', 'orbit:proxycli:a'], Mockery::any())->ordered()->andReturn(0);
    $client->shouldReceive('executeRaw')->once()->with(['SCAN', '17', 'MATCH', 'orbit:proxycli:*', 'COUNT', '100'], Mockery::any())->ordered()->andThrow(new RuntimeException('fixture connection lost'));
    $client->shouldReceive('executeRaw')->once()->with(['SCAN', '0', 'MATCH', 'orbit:proxycli:*', 'COUNT', '100'], Mockery::any())->ordered()->andReturn(['0', ['orbit:proxycli:b', 'orbit:proxycli:lock']]);
    $client->shouldReceive('executeRaw')->once()->with(['EXISTS', 'orbit:proxycli:b'], Mockery::any())->ordered()->andReturn(1);
    $client->shouldReceive('executeRaw')->once()->with(['RENAME', 'orbit:proxycli:b', 'orbit:ai-router:b'], Mockery::any())->ordered()->andReturn('OK');
    $client->shouldReceive('executeRaw')->once()->with(['DEL', 'orbit:proxycli:lock'], Mockery::any())->ordered()->andReturn(1);
    $cache = new ValkeyAiRouterCache(new DatabaseConnection, $client);

    expect(fn () => $cache->renameCollectorKeys('orbit:proxycli:', 'orbit:ai-router:'))->toThrow(RuntimeException::class, 'Could not reach shared Valkey');
    $cache->renameCollectorKeys('orbit:proxycli:', 'orbit:ai-router:');
});
