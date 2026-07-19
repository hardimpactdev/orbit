<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeStatus;
use App\Services\Dns\DnsmasqReconciler;
use App\Services\Nodes\NodeRegistryWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->reconciler = new class extends DnsmasqReconciler {
        public int $reconciles = 0;

        public function __construct() {}

        public function reconcileRecords(): bool
        {
            $this->reconciles++;

            return true;
        }
    };

    app()->instance(DnsmasqReconciler::class, $this->reconciler);
});

it('reconciles record projections when writing an active app node', function (): void {
    app(NodeRegistryWriter::class)->writeAppNode(
        name: 'app-1',
        tld: 'app-1',
        host: '10.6.0.3',
        wireguardAddress: '10.6.0.3',
        gatewayEndpoint: null,
        sshUser: 'orbit',
        user: 'orbit',
        status: NodeStatus::Active,
    );

    expect($this->reconciler->reconciles)->toBe(1);
});
