<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Dns\DnsmasqReconciler;
use App\Services\Dns\NodeDnsmasqRecordsBuilder;
use App\Services\Doctor\DoctorIssueFactory;
use App\Services\Doctor\DoctorNodeDnsProjectionCheck;
use App\Services\Doctor\NodeDnsProjectionProbe;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

afterEach(function (): void {
    $root = $this->root ?? null;

    if (is_string($root) && is_dir($root)) {
        File::deleteDirectory($root);
    }
});

it('checks the shared projection on the gateway and attributes issues in source order', function (): void {
    $this->root = sys_get_temp_dir().'/orbit-doctor-node-dns-check-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->root.'/dnsmasq.d');

    /** @var Node $gateway */
    $gateway = Node::factory()
        ->gateway()
        ->vpn()
        ->create([
            'name' => 'gateway',
            'tld' => 'gateway',
            'status' => 'active',
        ]);
    Node::factory()->create([
        'name' => 'source-z',
        'tld' => 'source-z',
        'status' => 'active',
    ]);
    Node::factory()->create([
        'name' => 'source-a',
        'tld' => 'source-a',
        'status' => 'active',
    ]);
    $reconciler = new class extends DnsmasqReconciler {
        public function __construct() {}

        public function projectionDirectoryIsMounted(): bool
        {
            return true;
        }
    };
    $check = new DoctorNodeDnsProjectionCheck(
        nodeRoleAssignments: app(NodeRoleAssignments::class),
        dnsmasqReconciler: $reconciler,
        nodeDnsProjectionProbe: new NodeDnsProjectionProbe(
            recordsBuilder: new NodeDnsmasqRecordsBuilder,
            rootPath: $this->root,
        ),
        doctorIssueFactory: app(DoctorIssueFactory::class),
    );

    $issues = $check->issues($gateway->fresh('roleAssignments'));

    expect(collect($issues)->pluck('node')->all())
        ->toBe(['gateway', 'source-z', 'source-a']);
});
