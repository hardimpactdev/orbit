<?php

declare(strict_types=1);

use App\Data\Doctor\DoctorIssue;
use App\Models\Node;
use App\Services\Dns\DnsmasqBaseConfigBuilder;
use App\Services\Dns\DnsmasqReconciler;
use App\Services\Dns\NodeDnsmasqRecordsBuilder;
use App\Services\Dns\ProxyDnsmasqRecordsBuilder;
use App\Services\Doctor\DnsRuntimeProbe;
use App\Services\Doctor\DoctorDnsRuntimeRestorer;
use App\Services\Doctor\DoctorIssueFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->dnsRoot = sys_get_temp_dir().'/orbit-doctor-dns-restorer-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->dnsRoot);
});

afterEach(function (): void {
    if (is_string($this->dnsRoot) && is_dir($this->dnsRoot)) {
        File::deleteDirectory($this->dnsRoot);
    }
});

it('returns null for an unsupported DNS runtime issue', function (): void {
    $node = Node::factory()->gateway()->create(['name' => 'gateway-node']);
    $restorer = new DoctorDnsRuntimeRestorer(doctor_dns_runtime_probe($this->dnsRoot));

    expect($restorer->apply($node, doctor_dns_runtime_issue(
        key: 'tool.capability_missing',
        summary: 'A normal tool is missing.',
        detail: ['tool' => 'composer'],
    )))->toBeNull();
});

it('preserves the completed DNS runtime restore action', function (): void {
    Process::fake([
        'docker restart orbit-dns' => Process::result(),
    ]);
    $node = Node::factory()->gateway()->create(['name' => 'gateway-node']);
    $restorer = new DoctorDnsRuntimeRestorer(doctor_dns_runtime_probe($this->dnsRoot));

    expect($restorer->apply($node, doctor_dns_runtime_issue(
        key: 'tool.dns_port_not_listening',
        summary: 'orbit-dns is not listening on port 53.',
        detail: ['container' => 'orbit-dns'],
    )))->toBe([
        'family' => 'tool',
        'node' => 'gateway-node',
        'code' => 'tool.dns_port_not_listening',
        'key' => 'tool.dns_port_not_listening',
        'mode' => 'restore',
        'status' => 'completed',
        'summary' => 'orbit-dns is not listening on port 53.',
        'details' => ['container' => 'orbit-dns'],
    ]);
});

it('preserves the false DNS runtime restore action', function (): void {
    File::put($this->dnsRoot.'/dnsmasq.conf', "address=/legacy.test/10.6.0.2\n");
    $node = Node::factory()->gateway()->create(['name' => 'gateway-node']);
    $restorer = new DoctorDnsRuntimeRestorer(doctor_dns_runtime_probe($this->dnsRoot));

    expect($restorer->apply($node, doctor_dns_runtime_issue(
        key: 'tool.dns_container_missing',
        summary: 'orbit-dns is missing.',
        detail: ['container' => 'orbit-dns'],
    )))->toBe([
        'family' => 'tool',
        'node' => 'gateway-node',
        'code' => 'tool.dns_container_missing',
        'key' => 'tool.dns_container_missing',
        'mode' => 'restore',
        'status' => 'failed',
        'summary' => 'Failed to fix tool.dns_container_missing.',
        'details' => [
            'container' => 'orbit-dns',
            'error' => 'restore_returned_false',
        ],
    ]);
});

it('preserves the failed DNS runtime restore action when recovery throws', function (): void {
    $invalidRoot = '/dev/null/orbit-doctor-dns-restorer';
    $reconciler = new DnsmasqReconciler(
        baseConfigBuilder: new DnsmasqBaseConfigBuilder,
        nodeRecordsBuilder: new NodeDnsmasqRecordsBuilder,
        proxyRecordsBuilder: new ProxyDnsmasqRecordsBuilder,
        rootPath: $invalidRoot,
    );
    $probe = new DnsRuntimeProbe(
        baseConfigBuilder: new DnsmasqBaseConfigBuilder,
        rootPath: $invalidRoot,
        dnsmasqReconciler: $reconciler,
    );
    $node = Node::factory()->gateway()->create(['name' => 'gateway-node']);

    $action = new DoctorDnsRuntimeRestorer($probe)->apply($node, doctor_dns_runtime_issue(
        key: 'tool.dns_container_missing',
        summary: 'orbit-dns is missing.',
        detail: ['container' => 'orbit-dns'],
    ));

    expect($action)
        ->toMatchArray([
            'family' => 'tool',
            'node' => 'gateway-node',
            'code' => 'tool.dns_container_missing',
            'key' => 'tool.dns_container_missing',
            'mode' => 'restore',
            'status' => 'failed',
            'summary' => 'Failed to fix tool.dns_container_missing.',
        ])
        ->and($action['details']['container'] ?? null)
        ->toBe('orbit-dns')
        ->and($action['details']['error'] ?? null)
        ->toBeString();
});

function doctor_dns_runtime_probe(string $root): DnsRuntimeProbe
{
    return new DnsRuntimeProbe(
        baseConfigBuilder: new DnsmasqBaseConfigBuilder,
        rootPath: $root,
    );
}

/** @param array<string, mixed> $detail */
function doctor_dns_runtime_issue(string $key, string $summary, array $detail): DoctorIssue
{
    return app(DoctorIssueFactory::class)->fromArray([
        'family' => 'tool',
        'node' => 'gateway-node',
        'key' => $key,
        'kind' => 'divergent',
        'summary' => $summary,
        'detail' => $detail,
    ]);
}
