<?php

declare(strict_types=1);

use App\Domain\AppDev\DnsPublishProjection;
use App\Domain\Operations\OperationStatus;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/** A running dns.publish Operation that carries the entry its Swamp run publishes. */
function dnsPublishOperation(): Operation
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'vpn', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.1',
        'wireguard_ip' => '10.44.0.1', 'user' => 'orbit']));
    $entry = ['node_id' => $node->id, 'connection' => ['nodeHost' => '10.44.0.1'], 'host_keys' => null,
        'dns' => ['revision' => '1790000000000001', 'listen' => ['10.44.0.1'], 'tlds' => [], 'records' => []]];

    return Operation::query()->create(['kind' => 'dns.publish', 'target_type' => 'private_dns', 'target_id' => $node->id, 'node_id' => $node->id,
        'status' => 'running', 'sequence' => 1, 'attempts' => 1, 'input' => ['intent' => $entry], 'state' => ['swamp_run_id' => 'fixture-dns-publish']]);
}

describe('DnsPublishProjection', function (): void {
    it('hands the run the entry the Gateway rendered and no secret', function (): void {
        $operation = dnsPublishOperation();

        expect(app(DnsPublishProjection::class)->input($operation))
            ->toBe(['intent' => $operation->input['intent'], 'execution_secrets' => []]);
    });

    it('records the revision a succeeded run published', function (): void {
        $operation = dnsPublishOperation();

        $projected = app(DnsPublishProjection::class)->apply($operation, ['run_id' => 'fixture-dns-publish', 'status' => 'succeeded',
            'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String()]);

        expect($projected->status)->toBe(OperationStatus::Succeeded)
            ->and($projected->result)->toBe(['revision' => '1790000000000001'])
            ->and($projected->lock_key)->toBeNull();
    });

    it('records the error code of a failed run', function (): void {
        $operation = dnsPublishOperation();

        $projected = app(DnsPublishProjection::class)->apply($operation, ['run_id' => 'fixture-dns-publish', 'status' => 'failed', 'error_code' => 'dns.check_failed',
            'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String()]);

        expect($projected->status)->toBe(OperationStatus::Failed)
            ->and($projected->error_code)->toBe('dns.check_failed')
            ->and($projected->result)->toBe(['error_code' => 'dns.check_failed']);
    });
});
