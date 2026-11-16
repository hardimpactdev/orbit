<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Infrastructure\GatewayReleases\GatewayReleaseAgentDelivery;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Releases\ReleaseProvenance;
use App\Models\Node;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('reads the commit pin from the raw host of a GitHub repository', function (string $repository, string $pin): void {
    $sha = str_repeat('a', 40);
    Http::fake(['*' => Http::response('', 504)]);
    $delivery = new GatewayReleaseAgentDelivery(app(ProcessRunner::class), app(ReleaseProvenance::class), $repository, sys_get_temp_dir());

    expect(fn () => $delivery->fetch($sha, new Node))->toThrow(GatewayReleaseException::class);

    Http::assertSent(static fn (Request $request): bool => $request->url() === sprintf($pin, $sha));
})->with([
    'github' => ['https://github.com/hardimpactdev/orbit/', 'https://raw.githubusercontent.com/hardimpactdev/orbit/%s/apps/gateway/resources/agent/frozen-manifest.json'],
    'other host' => ['https://git.example.test/orbit', 'https://git.example.test/orbit/raw/%s/apps/gateway/resources/agent/frozen-manifest.json'],
]);

/** A published manifest and its SHA256SUMS for the given artifact keys. @return array{string, array<string, string>} */
function agent_delivery_release(array $keys, string $base): array
{
    $artifacts = [];
    $checksums = [];
    foreach ($keys as $key) {
        $name = GatewayReleaseAgentDelivery::file($key);
        $checksums[$name] = hash('sha256', $name);
        $artifacts[$key] = ['path' => $name, 'sha256' => $checksums[$name], 'url' => $base.'/'.$name];
    }

    return [json_encode(['commit' => str_repeat('b', 40), 'version' => '0.16.0', 'artifacts' => $artifacts], JSON_THROW_ON_ERROR), $checksums];
}

it('accepts a delivery with and without the executor, and checks the executor like an agent', function (): void {
    $base = 'https://github.com/hardimpactdev/orbit/releases/download/cli-v0.5.0';
    $sha = str_repeat('a', 40);
    $agents = GatewayReleaseAgentDelivery::Platforms;

    [$before] = agent_delivery_release($agents, $base);
    [$with, $sums] = agent_delivery_release([...$agents, 'executor-linux-x86_64'], $base);
    GatewayReleaseAgentDelivery::validate($before, agent_delivery_release($agents, $base)[1], $base, $sha);
    GatewayReleaseAgentDelivery::validate($with, $sums, $base, $sha);

    expect(GatewayReleaseAgentDelivery::file('executor-linux-x86_64'))->toBe('orbit-executor-linux-x86_64')
        ->and(fn () => GatewayReleaseAgentDelivery::validate($with, [...$sums, 'orbit-executor-linux-x86_64' => str_repeat('0', 64)], $base, $sha))
        ->toThrow(GatewayReleaseException::class, 'release agent [executor-linux-x86_64] does not match')
        ->and(fn () => GatewayReleaseAgentDelivery::validate(agent_delivery_release([...$agents, 'executor-macos-x86_64'], $base)[0], $sums, $base, $sha))
        ->toThrow(GatewayReleaseException::class, 'does not name exactly the delivered platforms')
        ->and(fn () => GatewayReleaseAgentDelivery::validate(agent_delivery_release(['linux-x86_64', 'executor-linux-x86_64'], $base)[0], $sums, $base, $sha))
        ->toThrow(GatewayReleaseException::class, 'does not name exactly the delivered platforms');
});
