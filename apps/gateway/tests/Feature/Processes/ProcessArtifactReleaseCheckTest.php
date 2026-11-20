<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\Process;
use App\Models\ProcessArtifact;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-20T03:00:00Z'));
    Http::preventStrayRequests();
});

function release_check_artifact(string $name, string $repository, ?string $latest = null, ?string $checkedAt = null): ProcessArtifact
{
    $node = Node::query()->firstOrCreate(['name' => 'services'], ['status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.17', 'public_ssh_host' => '192.0.2.17', 'ssh_host_fingerprint' => 'SHA256:fixture', 'user' => 'orbit']);
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => $name,
        'runtime' => 'systemd', 'desired_state' => 'running', 'status' => 'active', 'runtime_config' => []]);

    return ProcessArtifact::query()->create(['process_id' => $process->id, 'source' => ProcessArtifact::SourceGithubRelease, 'repository' => $repository,
        'asset' => 'tool_{version}.tar.gz', 'binary' => 'tool', 'version' => '1.0.0', 'latest_version' => $latest, 'latest_checked_at' => $checkedAt]);
}

it('runs daily and reads each distinct repository once, storing the latest release on each of its artifacts', function (): void {
    $first = release_check_artifact('ai-router', 'router-for-me/CLIProxyAPI');
    $second = release_check_artifact('ai-router-spare', 'router-for-me/CLIProxyAPI');
    $other = release_check_artifact('tool', 'acme/tool');
    Http::fake([
        'api.github.com/repos/router-for-me/CLIProxyAPI/releases/latest' => Http::response(['tag_name' => 'v8.0.80', 'draft' => false, 'prerelease' => false]),
        'api.github.com/repos/acme/tool/releases/latest' => Http::response(['tag_name' => '2.1.0', 'draft' => false, 'prerelease' => false]),
    ]);

    Artisan::call('schedule:list');
    $event = collect(app(Schedule::class)->events())->first(fn (Event $event): bool => str_contains((string) $event->command, 'process:artifacts:check-latest')
        || $event->description === 'process:artifacts:check-latest');
    expect($event)->not->toBeNull()->and($event->expression)->toBe('0 0 * * *');

    expect(Artisan::call('process:artifacts:check-latest'))->toBe(0);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.github.com/repos/router-for-me/CLIProxyAPI/releases/latest');
    foreach ([$first, $second] as $artifact) {
        expect($artifact->fresh()->latest_version)->toBe('8.0.80')
            ->and($artifact->fresh()->latest_checked_at?->toIso8601String())->toBe('2026-11-20T03:00:00+00:00');
    }
    expect($other->fresh()->latest_version)->toBe('2.1.0');
});

it('keeps the last answer when GitHub fails or names only a prerelease', function (array $response): void {
    $artifact = release_check_artifact('ai-router', 'router-for-me/CLIProxyAPI', '8.0.63', '2026-11-19T03:00:00Z');
    Http::fake(['api.github.com/*' => Http::response(...$response)]);

    expect(Artisan::call('process:artifacts:check-latest'))->toBe(0);

    expect($artifact->fresh()->latest_version)->toBe('8.0.63')
        ->and($artifact->fresh()->latest_checked_at?->toIso8601String())->toBe('2026-11-19T03:00:00+00:00');
})->with([
    'server error' => [[['message' => 'boom'], 502]],
    'rate limited' => [[['message' => 'rate limit'], 403]],
    'prerelease' => [[['tag_name' => 'v8.1.0-rc1', 'draft' => false, 'prerelease' => true]]],
]);
