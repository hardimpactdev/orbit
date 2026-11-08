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
