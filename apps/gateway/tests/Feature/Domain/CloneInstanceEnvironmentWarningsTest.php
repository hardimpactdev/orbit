<?php

declare(strict_types=1);

use App\Domain\Instances\Environment\CloneEnvironmentWarnings;
use App\Models\Node;

it('detects development references with host and path boundaries', function (string $key, string $value, bool $warn): void {
    $node = new Node(['public_ssh_host' => 'dev-node.example.net', 'wireguard_ip' => '10.0.0.2', 'lan_ip' => '192.168.1.2', 'tld' => 'dev.orbit']);
    $warnings = (new CloneEnvironmentWarnings)->detect([$key => $value], $node, '/home/production');

    expect($warnings)->toHaveCount($warn ? 1 : 0);
    if ($warn) {
        expect($warnings[0])->toStartWith($key.':')->not->toContain($value);
    }
})->with([
    ['REDIS_HOST', '10.0.0.2', true],
    ['CACHE_HOST', '192.168.1.2', true],
    ['MAIL_HOST', 'DEV-NODE.EXAMPLE.NET', true],
    ['SESSION_DOMAIN', '.dev.orbit', true],
    ['REDIS_URL', 'redis://user:secret@cache.dev.orbit:6379/1', true],
    ['MAIL_HOST', 'dev.orbit.attacker.net', false],
    ['MAIL_HOST', 'notdev.orbit', false],
    ['REDIS_HOST', 'cache.production.example.net', false],
    ['APP_URL', 'https://{{instance.domain}}', false],
    ['CACHE_PATH', '/home/production/cache', false],
    ['CACHE_PATH', '/home/production', false],
    ['CACHE_PATH', '/home/production-other/cache', true],
    ['CACHE_PATH', '/home/production/../development/cache', true],
    ['CACHE_PATH', 'storage/cache', false],
    ['SESSION_PATH', '/', false],
    ['SESSION_PATH', '/account', false],
    ['ASSET_URL', '/assets', false],
    ['QUEUE_CONNECTION', 'database', true],
    ['QUEUE_CONNECTION', 'redis', false],
    ['MAIL_MAILER', 'log', true],
    ['SESSION_DRIVER', 'array', true],
    ['SESSION_DRIVER', 'file', false],
    ['CACHE_STORE', 'array', true],
    ['APP_KEY', 'base64:secret', false],
    ['DB_HOST', 'dev-node.example.net', false],
]);

it('does not flag fleet service hosts under the target TLD', function (string $host, bool $warn): void {
    $candidate = new Node(['public_ssh_host' => 'dev.orbit', 'tld' => 'orbit']);
    $target = new Node(['tld' => 'orbit']);

    $warnings = (new CloneEnvironmentWarnings)->detect(['SERVICE_HOST' => $host], $candidate, '/home/production', $target);

    expect($warnings)->toHaveCount($warn ? 1 : 0);
})->with([
    ['vault.orbit', false],
    ['https://vault.orbit/secret', false],
    ['dev.orbit', true],
    ['vault.orbit.attacker.net', false],
]);

it('still flags a distinct development TLD outside the target TLD', function (): void {
    $warnings = (new CloneEnvironmentWarnings)->detect(
        ['REDIS_HOST' => 'cache.dev.example'],
        new Node(['tld' => 'dev.example']),
        '/home/production',
        new Node(['tld' => 'prod.example']),
    );

    expect($warnings)->toHaveCount(1);
});
