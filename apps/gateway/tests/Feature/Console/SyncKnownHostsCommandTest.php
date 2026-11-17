<?php

declare(strict_types=1);

use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsRepository;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    Process::preventStrayProcesses();
    $this->folder = sys_get_temp_dir().'/orbit-known-hosts-'.bin2hex(random_bytes(8));
    $this->path = $this->folder.'/known_hosts';
    app()->instance(KnownHostsStore::class, new KnownHostsRepository($this->path));
});

afterEach(function (): void {
    foreach (glob($this->folder.'/*') ?: [] as $file) {
        unlink($file);
    }
    if (is_dir($this->folder)) {
        rmdir($this->folder);
    }
});

/** @param array<string, mixed> $attributes */
function nodeWithSshPin(array $attributes = []): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create(array_replace([
        'name' => 'app-dev', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit',
        'public_ssh_host' => '10.45.0.2', 'wireguard_ip' => '10.45.0.2', 'ssh_host_key_type' => 'ssh-ed25519',
        'ssh_host_key' => 'recorded-public-key', 'ssh_host_fingerprint' => 'SHA256:recorded',
    ], $attributes)));
}

it('restores pinned WireGuard hosts without scanning and leaves a current file in place', function (): void {
    nodeWithSshPin();
    nodeWithSshPin(['name' => 'app-prod', 'wireguard_ip' => '10.45.0.3', 'ssh_host_key' => 'another-recorded-public-key']);

    expect(Artisan::call('gateway:known-hosts:sync'))->toBe(0)
        ->and(json_decode(Artisan::output(), true))->toBe(['changed' => true]);
    $contents = file_get_contents($this->path);
    expect($contents)->toBe("10.45.0.2 ssh-ed25519 recorded-public-key\n10.45.0.3 ssh-ed25519 another-recorded-public-key\n");
    $inode = fileinode($this->path);
    expect(Artisan::call('gateway:known-hosts:sync'))->toBe(0)
        ->and(json_decode(Artisan::output(), true))->toBe(['changed' => false]);
    clearstatcache(true, $this->path);
    expect(file_get_contents($this->path))->toBe($contents)
        ->and(fileinode($this->path))->toBe($inode)
        ->and(fileperms($this->path) & 0o777)->toBe(0o600);
});

it('repairs modes even when the recorded pins already match and reports the repair once', function (int $fileMode, int $directoryMode): void {
    nodeWithSshPin();
    Artisan::call('gateway:known-hosts:sync');
    $contents = file_get_contents($this->path);
    chmod($this->path, $fileMode);
    chmod($this->folder, $directoryMode);

    expect(Artisan::call('gateway:known-hosts:sync'))->toBe(0)
        ->and(json_decode(Artisan::output(), true))->toBe(['changed' => true]);
    clearstatcache();
    expect(fileperms($this->path) & 0o7777)->toBe(0o600)
        ->and(fileperms($this->folder) & 0o7777)->toBe(0o700)
        ->and(file_get_contents($this->path))->toBe($contents);

    expect(Artisan::call('gateway:known-hosts:sync'))->toBe(0)
        ->and(json_decode(Artisan::output(), true))->toBe(['changed' => false]);
})->with(['file' => [0o666, 0o700], 'directory' => [0o600, 0o777], 'both' => [0o666, 0o777]]);

it('replaces stale pins and preserves unrelated hosts', function (): void {
    $store = app(KnownHostsStore::class);
    $store->put('unrelated.example', 2222, new HostKey('ssh-ed25519', 'unrelated-public-key', 'SHA256:unrelated'));
    $store->put('10.45.0.2', 22, new HostKey('ssh-ed25519', 'stale-public-key', 'SHA256:stale'));
    nodeWithSshPin();

    expect(Artisan::call('gateway:known-hosts:sync'))->toBe(0)
        ->and(json_decode(Artisan::output(), true))->toBe(['changed' => true])
        ->and(file_get_contents($this->path))->toBe("[unrelated.example]:2222 ssh-ed25519 unrelated-public-key\n10.45.0.2 ssh-ed25519 recorded-public-key\n");
});

it('writes no host without its complete recorded pin', function (string $field, ?string $value): void {
    nodeWithSshPin([$field => $value]);
    expect(Artisan::call('gateway:known-hosts:sync'))->toBe(0)
        ->and(json_decode(Artisan::output(), true))->toBe(['changed' => false])
        ->and(file_exists($this->path))->toBeFalse();
})->with([
    ['wireguard_ip', null], ['wireguard_ip', ''], ['ssh_host_key_type', null], ['ssh_host_key_type', ''],
    ['ssh_host_key', null], ['ssh_host_key', ''], ['ssh_host_fingerprint', null], ['ssh_host_fingerprint', ''],
]);
