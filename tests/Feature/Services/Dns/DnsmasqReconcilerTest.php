<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Dns\DnsmasqConfigBuilder;
use App\Services\Dns\DnsmasqReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->workdir = sys_get_temp_dir().'/orbit-dns-reconciler-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->workdir);
    $this->confPath = $this->workdir.'/dnsmasq.conf';
});

afterEach(function (): void {
    if (isset($this->workdir) && is_string($this->workdir) && is_dir($this->workdir)) {
        File::deleteDirectory($this->workdir);
    }
});

it('writes dnsmasq.conf and sighups orbit-dns when state changes', function (): void {
    Process::fake();

    Node::factory()->create([
        'name' => 'gateway',
        'role' => 'gateway',
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);

    (new DnsmasqReconciler(
        configBuilder: new DnsmasqConfigBuilder,
        rootPath: $this->workdir,
    ))->reconcile();

    expect(File::exists($this->confPath))->toBeTrue()
        ->and(File::get($this->confPath))->toContain('address=/.gateway/10.6.0.2');

    Process::assertRan(fn ($process): bool => str_contains(
        (string) $process->command,
        'docker exec orbit-dns kill -HUP 1',
    ));
});

it('is a no-op when the on-disk config already matches state', function (): void {
    Process::fake();

    Node::factory()->create([
        'name' => 'gateway',
        'role' => 'gateway',
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);

    $expected = (new DnsmasqConfigBuilder)->build(Node::query()->get());
    File::put($this->confPath, $expected);

    (new DnsmasqReconciler(
        configBuilder: new DnsmasqConfigBuilder,
        rootPath: $this->workdir,
    ))->reconcile();

    Process::assertNothingRan();
});

it('rewrites the conf and sighups when fleet state changes', function (): void {
    Process::fake();

    Node::factory()->create([
        'name' => 'gateway',
        'role' => 'gateway',
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);

    $reconciler = new DnsmasqReconciler(
        configBuilder: new DnsmasqConfigBuilder,
        rootPath: $this->workdir,
    );

    $reconciler->reconcile();

    Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'tld' => 'app-1.test',
        'wireguard_address' => '10.6.0.3',
    ]);

    $reconciler->reconcile();

    expect(File::get($this->confPath))->toContain('address=/.app-1.test/10.6.0.3');
});
