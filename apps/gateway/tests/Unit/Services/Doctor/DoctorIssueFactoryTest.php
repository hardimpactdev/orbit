<?php

declare(strict_types=1);

use App\Data\Doctor\DoctorIssue;
use App\Data\Doctor\DriftEntry;
use App\Enums\DoctorIssueDisposition;
use App\Enums\DriftKind;
use App\Exceptions\DoctorIssueIdentityMismatch;
use App\Exceptions\DoctorUncataloguedIssueException;
use App\Services\Doctor\DoctorIssueFactory;
use App\Services\Doctor\DoctorIssueIdentityResolver;

function doctor_issue_factory(): DoctorIssueFactory
{
    return new DoctorIssueFactory(new DoctorIssueIdentityResolver);
}

it('provides one typed Doctor issue contract and factory', function (): void {
    expect(class_exists(DoctorIssue::class))
        ->toBeTrue()
        ->and(class_exists(DoctorIssueFactory::class))
        ->toBeTrue()
        ->and(method_exists(DoctorIssue::class, 'toArray'))
        ->toBeTrue()
        ->and(method_exists(DoctorIssueFactory::class, 'fromArray'))
        ->toBeTrue();
});

it('rebuilds a selected issue from catalogued observation fields', function (): void {
    $issue = doctor_issue_factory()->fromClientArray([
        'family' => 'node',
        'node' => 'beast',
        'key' => 'node.access_permission_invalid',
        'kind' => 'divergent',
        'summary' => 'Managed access permissions differ.',
        'detail' => ['path' => '/home/orbit/.ssh'],
        'disposition' => 'runtime_incident',
        'restore_action' => null,
        'restorable' => false,
        'adoptable' => true,
    ]);

    expect($issue)
        ->toBeInstanceOf(DoctorIssue::class)
        ->and($issue->disposition)
        ->toBe(DoctorIssueDisposition::GenuineDrift)
        ->and($issue->restorable)
        ->toBeTrue()
        ->and($issue->adoptable)
        ->toBeFalse()
        ->and($issue->toArray())
        ->toBe([
            'family' => 'node',
            'node' => 'beast',
            'key' => 'node.access_permission_invalid',
            'code' => 'node.access_permission_invalid',
            'kind' => 'divergent',
            'summary' => 'Managed access permissions differ.',
            'detail' => ['path' => '/home/orbit/.ssh'],
            'disposition' => 'genuine_drift',
            'restore_action' => 'restore_node_access_permission_invalid',
            'restorable' => true,
            'adoptable' => false,
        ]);
});

it('uses an explicit catalog code for a resource-scoped key', function (): void {
    $issue = doctor_issue_factory()->fromClientArray([
        'family' => 'proxy',
        'node' => 'gateway',
        'key' => 'docs.test',
        'code' => 'proxy.route_extra',
        'kind' => 'extra',
        'summary' => 'An unmanaged proxy route exists.',
        'detail' => ['domain' => 'docs.test'],
    ]);

    expect($issue->code)
        ->toBe('proxy.route_extra')
        ->and($issue->restorable)
        ->toBeTrue()
        ->and($issue->adoptable)
        ->toBeTrue();
});

it('rejects a catalogued key that claims a different catalog code', function (): void {
    doctor_issue_factory()->fromClientArray([
        'family' => 'app',
        'node' => 'beast',
        'key' => 'app.runtime_config_probe_failed',
        'code' => 'app.runtime_config_extra',
        'kind' => 'unverifiable',
        'summary' => 'Runtime config inspection failed.',
        'detail' => [],
        'restorable' => true,
    ]);
})->throws(DoctorIssueIdentityMismatch::class);

it('accepts a specific node update code under the shared node updates key', function (): void {
    $issue = doctor_issue_factory()->fromClientArray([
        'family' => 'node',
        'node' => 'beast',
        'key' => 'node.updates',
        'code' => 'node.updates_config_mismatch',
        'kind' => 'divergent',
        'summary' => 'Update policy differs.',
        'detail' => [],
    ]);

    expect($issue->key)
        ->toBe('node.updates')
        ->and($issue->code)
        ->toBe('node.updates_config_mismatch')
        ->and($issue->restorable)
        ->toBeTrue();
});

it('encodes an empty detail map as a JSON object', function (): void {
    $issue = doctor_issue_factory()->fromArray([
        'family' => 'node',
        'node' => 'beast',
        'key' => 'node.updates',
        'code' => 'node.updates_config_mismatch',
        'kind' => 'divergent',
        'summary' => 'Update policy differs.',
        'detail' => [],
    ]);

    $json = json_encode($issue->toArray(), JSON_THROW_ON_ERROR);

    expect($json)->toContain('"detail":{}');
    expect($json)->not->toContain('"detail":[]');
});

it('rejects an issue code from a different family', function (): void {
    doctor_issue_factory()->fromClientArray([
        'family' => 'proxy',
        'node' => 'gateway',
        'key' => 'docs.test',
        'code' => 'node.access_permission_invalid',
        'kind' => 'divergent',
        'summary' => 'Crafted issue.',
        'detail' => ['domain' => 'docs.test'],
    ]);
})->throws(DoctorIssueIdentityMismatch::class);

it('rejects conflicting top-level and detail codes', function (): void {
    doctor_issue_factory()->fromClientArray([
        'family' => 'proxy',
        'node' => 'gateway',
        'key' => 'docs.test',
        'code' => 'proxy.route_missing',
        'kind' => 'missing',
        'summary' => 'Crafted issue.',
        'detail' => [
            'domain' => 'docs.test',
            'code' => 'proxy.route_extra',
        ],
    ]);
})->throws(DoctorIssueIdentityMismatch::class);

it('derives adoption support from the catalog instead of client kind', function (): void {
    $factory = doctor_issue_factory();
    $missingRouteWithForgedKind = $factory->fromClientArray([
        'family' => 'proxy',
        'node' => 'gateway',
        'key' => 'docs.test',
        'code' => 'proxy.route_missing',
        'kind' => 'extra',
        'summary' => 'Crafted missing route.',
        'detail' => ['domain' => 'docs.test'],
        'adoptable' => true,
    ]);
    $extraRouteWithForgedKind = $factory->fromClientArray([
        'family' => 'proxy',
        'node' => 'gateway',
        'key' => 'docs.test',
        'code' => 'proxy.route_extra',
        'kind' => 'missing',
        'summary' => 'Observed extra route.',
        'detail' => ['domain' => 'docs.test'],
        'adoptable' => false,
    ]);

    expect($missingRouteWithForgedKind->adoptable)
        ->toBeFalse()
        ->and($extraRouteWithForgedKind->adoptable)
        ->toBeTrue();
});

it('uses a detail catalog code before a catalogued key fallback', function (): void {
    $factory = doctor_issue_factory();

    $detailCodeIssue = $factory->fromArray([
        'family' => 'proxy',
        'node' => 'gateway',
        'key' => 'docs.test',
        'kind' => 'missing',
        'summary' => 'A managed proxy route is missing.',
        'detail' => ['code' => 'proxy.route_missing'],
    ]);
    $keyCodeIssue = $factory->fromArray([
        'family' => 'app',
        'node' => 'beast',
        'key' => 'app.runtime_config_probe_failed',
        'kind' => 'unverifiable',
        'summary' => 'Runtime config inspection failed.',
        'detail' => ['error' => 'permission denied'],
    ]);

    expect($detailCodeIssue->code)
        ->toBe('proxy.route_missing')
        ->and($keyCodeIssue->code)
        ->toBe('app.runtime_config_probe_failed')
        ->and($keyCodeIssue->disposition)
        ->toBe(DoctorIssueDisposition::BlockedInspection)
        ->and($keyCodeIssue->restorable)
        ->toBeFalse();
});

it('builds a canonical issue directly from a drift entry', function (): void {
    $issue = doctor_issue_factory()->fromDriftEntry(
        new DriftEntry(
            family: 'node',
            key: 'node.access_permission_invalid',
            kind: DriftKind::Divergent,
            summary: 'Managed access permissions differ.',
            detail: ['path' => '/home/orbit/.ssh'],
        ),
        node: 'beast',
    );

    expect($issue->family)
        ->toBe('node')
        ->and($issue->node)
        ->toBe('beast')
        ->and($issue->code)
        ->toBe('node.access_permission_invalid')
        ->and($issue->detail)
        ->toBe(['path' => '/home/orbit/.ssh']);
});

it('fails closed when an observation has no catalogued code', function (): void {
    doctor_issue_factory()->fromArray([
        'family' => 'node',
        'node' => 'beast',
        'key' => 'node.not_catalogued',
        'kind' => 'unknown',
        'summary' => 'Unknown observation.',
        'detail' => [],
    ]);
})->throws(DoctorUncataloguedIssueException::class);
