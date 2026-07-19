<?php

declare(strict_types=1);

use App\Services\Dns\DnsmasqReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->dnsmasqReconciler = new class extends DnsmasqReconciler {
        public int $reconciles = 0;

        public function __construct() {}

        public function reconcileRecords(): bool
        {
            $this->reconciles++;

            return true;
        }
    };
    app()->instance(DnsmasqReconciler::class, $this->dnsmasqReconciler);
});

it('registers a node through the hidden internal bootstrap command', function (): void {
    $this
        ->artisan('orbit:internal:node-register', [
            'name' => 'gateway',
            '--tld' => 'gateway',
            '--host' => 'gateway',
            '--user' => 'gateway',
            '--orbit-path' => '/home/gateway/orbit',
        ])
        ->expectsOutputToContain('Registered node gateway.')
        ->assertSuccessful();

    $node = DB::table('nodes')->where('name', 'gateway')->first();

    expect($node)
        ->not->toBeNull()->and((array) $node)
        ->not->toHaveKeys(['role', 'environment'])->and($node->host)->toBe('gateway')->and($node->user)->toBe(
            'gateway',
        )->and($node->orbit_path)->toBe('/home/gateway/orbit')->and($node->tld)->toBe(
            'gateway',
        )->and($this->dnsmasqReconciler->reconciles)->toBe(1);
});

it('rejects invalid or reserved TLDs for active node registration', function (string $tld): void {
    $this
        ->artisan('orbit:internal:node-register', [
            'name' => 'gateway',
            '--tld' => $tld,
        ])
        ->expectsOutputToContain('Active nodes require a unique non-reserved lowercase DNS-label TLD.')
        ->assertFailed();

    expect(DB::table('nodes')->where('name', 'gateway')->exists())
        ->toBeFalse()
        ->and($this->dnsmasqReconciler->reconciles)
        ->toBe(0);
})->with([
    'private service namespace' => 'orbit',
    'surrounding whitespace' => ' fleet ',
]);
