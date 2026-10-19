<?php

declare(strict_types=1);

use App\Models\DatabaseConnection;
use Orbit\Extensions\AiRouter\Infrastructure\ValkeyAiRouterCache;
use Predis\Client;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

// Explicit check in the disposable Beast checkout with its own Docker Valkey, never fleet Valkey.
it('renames collector state atomically without changing Valkey expiry deadlines', function (bool $duplicateScan): void {
    $name = 'ai-router-1352-cache-'.bin2hex(random_bytes(6));
    $server = new Process(['docker', 'run', '--rm', '--name', $name, '--label', 'orbit.test=ai-router-1352',
        '--publish', '127.0.0.1::6379', 'valkey/valkey:8.1', 'valkey-server', '--save', '', '--appendonly', 'no']);
    $server->start();
    try {
        expect($server->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'Ready to accept connections')))->toBeTrue();
        $published = new Process(['docker', 'port', $name, '6379/tcp']);
        $published->mustRun();
        $port = (int) substr(trim($published->getOutput()), strlen('127.0.0.1:'));
        $client = new Client(['host' => '127.0.0.1', 'port' => $port]);
        $cache = new ValkeyAiRouterCache(new DatabaseConnection(['driver' => 'redis', 'host' => '127.0.0.1', 'port' => $port]));
        $cache->put('orbit:proxycli:backoff:limited', 'retry-after', 300);
        $cache->put('orbit:proxycli:account:limited', 'next-check', 600);
        $cache->put('orbit:proxycli:snapshot', 'snapshot');
        $cache->put('orbit:proxycli:lock', 'old-holder', 120);
        $cache->put('unrelated', 'keep');
        $backoff = $client->executeRaw(['PEXPIRETIME', 'orbit:proxycli:backoff:limited']);
        $account = $client->executeRaw(['PEXPIRETIME', 'orbit:proxycli:account:limited']);

        if ($duplicateScan) {
            // Fault injection: SCAN may repeat a key. Execute the actual Lua body with that result forced.
            $source = file_get_contents(base_path('extensions/ai-router/src/Infrastructure/ValkeyAiRouterCache.php'));
            expect(preg_match("/<<<'LUA'\n(.*?)\n\s*LUA;/s", $source, $match))->toBe(1);
            $script = str_replace('cursor = page[1]', 'cursor = page[1]
                for _, key in ipairs(page[2]) do
                    if key ~= ARGV[1] .. "lock" then table.insert(page[2], key); break end
                end', $match[1]);
            expect($client->executeRaw(['EVAL', $script, '0', 'orbit:proxycli:', 'orbit:ai-router:']))->toBe(3);
        } else {
            $cache->renameCollectorKeys('orbit:proxycli:', 'orbit:ai-router:');
        }
        $cache->renameCollectorKeys('orbit:proxycli:', 'orbit:ai-router:');

        expect($cache->get('orbit:ai-router:backoff:limited'))->toBe('retry-after')
            ->and($client->executeRaw(['PEXPIRETIME', 'orbit:ai-router:backoff:limited']))->toBe($backoff)
            ->and($cache->get('orbit:ai-router:account:limited'))->toBe('next-check')
            ->and($client->executeRaw(['PEXPIRETIME', 'orbit:ai-router:account:limited']))->toBe($account)
            ->and($cache->get('orbit:ai-router:snapshot'))->toBe('snapshot')
            ->and($client->executeRaw(['PTTL', 'orbit:ai-router:snapshot']))->toBe(-1)
            ->and($client->keys('orbit:proxycli:*'))->toBe([])
            ->and($cache->get('orbit:ai-router:lock'))->toBeNull()
            ->and($cache->get('unrelated'))->toBe('keep');
    } finally {
        new Process(['docker', 'stop', '--time=0', $name])->run();
        $server->stop();
    }
})->with(['normal SCAN' => false, 'duplicate SCAN result' => true]);
