<?php

declare(strict_types=1);

use App\Data\Doctor\DoctorIssue;
use App\Models\Node;
use App\Services\Dns\DnsmasqReconciler;
use App\Services\Doctor\DoctorDnsProjectionRestorer;
use App\Services\Doctor\DoctorIssueFactory;
use App\Services\Doctor\DoctorIssueNodeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('routes each private DNS projection to its owning family', function (
    string $key,
    string $family,
    string $calledMethod,
): void {
    $fallback = Node::factory()->create(['name' => 'fallback-node']);
    Node::factory()->create(['name' => 'issue-node']);
    $reconciler = new class extends DnsmasqReconciler {
        public ?string $calledMethod = null;

        public function __construct() {}

        public function projectionDirectoryIsMounted(): bool
        {
            return true;
        }

        public function reconcileNodeRecords(): bool
        {
            $this->calledMethod = 'node';

            return true;
        }

        public function reconcileProxyRecords(): bool
        {
            $this->calledMethod = 'proxy';

            return true;
        }
    };
    $restorer = new DoctorDnsProjectionRestorer($reconciler, app(DoctorIssueNodeResolver::class));
    $issue = app(DoctorIssueFactory::class)->fromArray([
        'family' => $family,
        'node' => 'issue-node',
        'key' => $key,
        'code' => $key,
        'kind' => 'divergent',
        'summary' => 'Private DNS projection is stale.',
        'detail' => ['path' => '/managed/projection'],
    ]);

    $action = $restorer->apply($fallback, $issue);

    expect($action)
        ->toBe([
            'family' => $family,
            'node' => 'issue-node',
            'code' => $key,
            'key' => $key,
            'mode' => 'restore',
            'status' => 'completed',
            'summary' => 'Private DNS projection is stale.',
            'details' => ['path' => '/managed/projection'],
        ])
        ->and($reconciler->calledMethod)
        ->toBe($calledMethod);
})->with([
    'node projection' => ['node.dns_mapping_mismatch', 'node', 'node'],
    'proxy projection' => ['proxy.dns_mapping_mismatch', 'proxy', 'proxy'],
]);

it('returns a failed Doctor action when the live projection directory is absent', function (): void {
    $node = Node::factory()->create(['name' => 'issue-node']);
    $reconciler = new class extends DnsmasqReconciler {
        public function __construct() {}

        public function projectionDirectoryIsMounted(): bool
        {
            return false;
        }
    };
    $restorer = new DoctorDnsProjectionRestorer($reconciler, app(DoctorIssueNodeResolver::class));

    expect($restorer->apply($node, doctor_dns_projection_restorer_issue('node.dns_mapping_mismatch')))
        ->toMatchArray([
            'family' => 'node',
            'node' => 'issue-node',
            'status' => 'failed',
            'details' => [
                'error' => 'The live orbit-dns runtime does not consume the managed projection directory.',
            ],
        ]);
});

it('returns a failed Doctor action when projection repair throws', function (): void {
    $node = Node::factory()->create(['name' => 'issue-node']);
    $reconciler = new class extends DnsmasqReconciler {
        public function __construct() {}

        public function projectionDirectoryIsMounted(): bool
        {
            return true;
        }

        public function reconcileProxyRecords(): bool
        {
            throw new \RuntimeException('projection failed');
        }
    };
    $restorer = new DoctorDnsProjectionRestorer($reconciler, app(DoctorIssueNodeResolver::class));

    expect($restorer->apply($node, doctor_dns_projection_restorer_issue('proxy.dns_mapping_mismatch')))
        ->toMatchArray([
            'family' => 'proxy',
            'node' => 'issue-node',
            'status' => 'failed',
            'details' => ['error' => 'projection failed'],
        ]);
});

function doctor_dns_projection_restorer_issue(string $key): DoctorIssue
{
    return app(DoctorIssueFactory::class)->fromArray([
        'family' => str_starts_with($key, 'node.') ? 'node' : 'proxy',
        'node' => 'issue-node',
        'key' => $key,
        'code' => $key,
        'kind' => 'divergent',
        'summary' => 'Private DNS projection is stale.',
        'detail' => [],
    ]);
}
