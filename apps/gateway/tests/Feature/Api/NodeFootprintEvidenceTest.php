<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeFootprintEvidence;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

function footprintEvidenceOperation(array $plan, array $children = [], array $recorded = []): Operation
{
    return (new Operation)->forceFill(['id' => 7, 'kind' => 'node.converge', 'node_id' => 2,
        'input' => ['first_visit' => false, 'force_artifacts' => false, '_footprint' => [
            'artifacts' => $plan, 'children' => $children, 'recorded' => $recorded,
        ]], 'state' => ['node_footprint_prepared' => true, 'executor_runs' => []]]);
}
function footprintEvidenceArtifact(string $name, bool $required = true, bool $repair = false): array
{
    return ['name' => $name, 'digest' => 'digest-'.$name, 'required' => $required, 'repair' => $repair];
}
function footprintEvidenceChild(string $artifact, string $kind, string $status = 'succeeded', array $result = [], ?string $error = null): Operation
{
    $child = (new Operation)->forceFill(['id' => 20, 'parent_operation_id' => 7, 'child_key' => 'footprint-'.$artifact,
        'target_type' => 'node', 'target_id' => 2, 'run_constraints' => [],
        'kind' => $kind, 'node_id' => 2, 'input' => [], 'status' => $status, 'result' => $result, 'error_code' => $error]);
    $snapshot = ['intent' => ['key' => $child->child_key, 'kind' => $kind, 'node_id' => 2, 'input' => []],
        'admitted' => ['parent_operation_id' => 7, 'key' => $child->child_key, 'kind' => $kind, 'node_id' => 2,
            'target_type' => 'node', 'target_id' => 2, 'input' => [], 'run_constraints' => []]];
    $child->forceFill(['child_admission' => $snapshot, 'child_fingerprint' => OperationChildIntent::fingerprint($snapshot)]);

    return $child;
}

it('converts artifact maps to the exact list shape and retires an absent artifact', function (): void {
    $operation = footprintEvidenceOperation([footprintEvidenceArtifact('agent', false)], recorded: ['agent' => 'digest-agent', 'retired' => 'old']);
    $derived = app(NodeFootprintEvidence::class)->derive($operation, []);

    expect($derived['result']['artifacts'])->toBe([['name' => 'agent', 'status' => 'unchanged', 'reason' => null]])
        ->and($derived['result']['skipped'])->toBe([])
        ->and($derived['result']['changed'])->toBeFalse()
        ->and($derived['recorded'])->toBe(['agent' => 'digest-agent']);
});

it('proves direct effects from run receipts instead of caller-posted steps', function (bool $forged): void {
    $operation = footprintEvidenceOperation([footprintEvidenceArtifact('tmpfiles')]);
    $state = $forged ? ['steps' => ['footprint-tmpfiles' => ['changed' => true]]]
        : ['executor_runs' => ['footprint-tmpfiles' => ['transport' => 'agent', 'name' => 'node.publish-tmpfiles', 'result' => ['changed' => false]]]];
    $operation->state = $state;
    if ($forged) {
        expect(fn () => app(NodeFootprintEvidence::class)->derive($operation, []))->toThrow(ResourceOperationException::class);
    } else {
        $derived = app(NodeFootprintEvidence::class)->derive($operation, []);
        expect($derived['result']['artifacts'])->toBe([['name' => 'tmpfiles', 'status' => 'unchanged', 'reason' => null]]);
    }
})->with([true, false]);

it('reports only a verified failed child in the skipped list', function (): void {
    $operation = footprintEvidenceOperation([footprintEvidenceArtifact('caddy')], ['caddy' => ['kind' => 'route.caddy_converge', 'node_id' => 2, 'input' => []]]);
    $child = footprintEvidenceChild('caddy', 'route.caddy_converge', 'failed', ['stage' => 'render'], 'caddy.render_failed');
    $derived = app(NodeFootprintEvidence::class)->derive($operation, [$child]);

    expect($derived['result']['artifacts'])->toBe([['name' => 'caddy', 'status' => 'skipped', 'reason' => 'caddy_render_refused']])
        ->and($derived['result']['skipped'])->toBe([['child_id' => 20, 'key' => 'footprint-caddy', 'kind' => 'route.caddy_converge', 'reason' => 'caddy_render_refused']])
        ->and($derived['error_code'])->toBeNull();
});

it('fails first-Node Caddy validation for changed code while preserving live repair skips', function (bool $first, bool $repair): void {
    $operation = footprintEvidenceOperation([footprintEvidenceArtifact('caddy', repair: $repair)], ['caddy' => ['kind' => 'route.caddy_converge', 'node_id' => 2, 'input' => []]]);
    $input = $operation->input;
    $input['first_visit'] = $first;
    $operation->input = $input;
    $child = footprintEvidenceChild('caddy', 'route.caddy_converge', 'failed', ['stage' => 'validate'], 'caddy.validate_failed');
    $derived = app(NodeFootprintEvidence::class)->derive($operation, [$child]);

    expect($derived['error_code'])->toBe($first && ! $repair ? 'node.footprint_caddy_failed' : null)
        ->and($derived['recorded'])->toBe(['caddy' => 'digest-caddy'])
        ->and($derived['result']['skipped'][0]['child_id'])->toBe(20);
})->with([[true, false], [true, true], [false, false]]);

it('refuses an unrelated, moved or unsettled child', function (string $variant): void {
    $operation = footprintEvidenceOperation([footprintEvidenceArtifact('caddy')], ['caddy' => ['kind' => 'route.caddy_converge', 'node_id' => 2, 'input' => []]]);
    $child = footprintEvidenceChild('caddy', 'route.caddy_converge');
    $child->forceFill(match ($variant) {
        'parent' => ['parent_operation_id' => 8], 'node' => ['node_id' => 3], 'kind' => ['kind' => 'route.private_dns_publish'],
        'key' => ['child_key' => 'another-key'], 'input' => ['input' => ['reason' => 'unadmitted']], 'pending' => ['status' => 'running'],
    });

    expect(fn () => app(NodeFootprintEvidence::class)->derive($operation, [$child]))->toThrow(ResourceOperationException::class);
})->with(['parent', 'node', 'kind', 'key', 'input', 'pending']);

it('retains unreached digests and forgets the failed artifact', function (): void {
    $operation = footprintEvidenceOperation([footprintEvidenceArtifact('tmpfiles'), footprintEvidenceArtifact('caddy')], recorded: ['tmpfiles' => 'old-tmp', 'caddy' => 'old-caddy']);
    $operation->state = ['executor_runs' => ['footprint-tmpfiles' => ['transport' => 'agent', 'name' => 'node.publish-tmpfiles', 'result' => ['error_code' => 'node.tmpfiles_failed']]]];
    $derived = app(NodeFootprintEvidence::class)->derive($operation, []);

    expect($derived['recorded'])->toBe(['caddy' => 'old-caddy'])
        ->and($derived['error_code'])->toBe('node.tmpfiles_failed');
});

it('forgets the agent digest after a failed install before configuration', function (): void {
    $operation = footprintEvidenceOperation([footprintEvidenceArtifact('agent')], recorded: ['agent' => 'old-agent']);
    $operation->state = ['executor_runs' => ['footprint-agent-install' => ['transport' => 'node-agent', 'name' => 'agent.install', 'result' => ['error_code' => 'agent.install_failed']]]];
    $derived = app(NodeFootprintEvidence::class)->derive($operation, []);

    expect($derived['recorded'])->toBe([])->and($derived['error_code'])->toBe('agent.install_failed');
});

it('skips failed residue cleanup but stops on private DNS failure', function (bool $residue): void {
    $artifact = $residue ? 'route-residue' : 'private-dns';
    $kind = $residue ? 'route.offline_residue_clean' : 'route.private_dns_publish';
    $operation = footprintEvidenceOperation([footprintEvidenceArtifact($artifact)], [$artifact => ['kind' => $kind, 'node_id' => 2, 'input' => []]]);
    $child = footprintEvidenceChild($artifact, $kind, 'failed', [], 'route.cleanup_failed');
    if (! $residue) {
        $operation->state = ['executor_runs' => ['footprint-private-dns-listener' => ['transport' => 'agent', 'name' => 'node.install-private-dns-listener', 'result' => ['installed' => true]]]];
    }
    $derived = app(NodeFootprintEvidence::class)->derive($operation, [$child]);

    expect($derived['error_code'])->toBe($residue ? null : 'route.cleanup_failed')
        ->and($derived['result']['skipped'])->toHaveCount(1);
})->with([true, false]);

it('requires proven listener setup before accepting private DNS publication', function (string $variant): void {
    $operation = footprintEvidenceOperation([footprintEvidenceArtifact('private-dns')], ['private-dns' => ['kind' => 'route.private_dns_publish', 'node_id' => 2, 'input' => []]]);
    $child = footprintEvidenceChild('private-dns', 'route.private_dns_publish');
    $run = ['transport' => 'agent', 'name' => 'node.install-private-dns-listener', 'result' => ['installed' => true]];
    if ($variant === 'transport') {
        $run['transport'] = 'remote_program';
    }
    if ($variant === 'command') {
        $run['name'] = 'node.publish-tmpfiles';
    }
    if ($variant === 'result') {
        $run['result'] = ['changed' => true];
    }
    $operation->state = ['executor_runs' => $variant === 'missing' ? [] : ['footprint-private-dns-listener' => $run]];
    if ($variant === 'proven') {
        expect(app(NodeFootprintEvidence::class)->derive($operation, [$child])['error_code'])->toBeNull();
    } else {
        expect(fn () => app(NodeFootprintEvidence::class)->derive($operation, [$child]))->toThrow(ResourceOperationException::class);
    }
})->with(['missing', 'transport', 'command', 'result', 'proven']);

it('drops the private DNS digest when listener setup fails before child admission', function (): void {
    $operation = footprintEvidenceOperation([footprintEvidenceArtifact('private-dns')], ['private-dns' => ['kind' => 'route.private_dns_publish', 'node_id' => 2, 'input' => []]], ['private-dns' => 'old']);
    $operation->state = ['executor_runs' => ['footprint-private-dns-listener' => ['transport' => 'agent', 'name' => 'node.install-private-dns-listener', 'result' => ['error_code' => 'node.private_dns_listener_failed']]]];
    $derived = app(NodeFootprintEvidence::class)->derive($operation, []);
    expect($derived['error_code'])->toBe('node.private_dns_listener_failed')
        ->and($derived['recorded'])->toBe([])->and($derived['result']['skipped'])->toBe([]);
});

it('refuses a failed child whose admission snapshot was never stamped', function (): void {
    $operation = footprintEvidenceOperation([footprintEvidenceArtifact('caddy')], ['caddy' => ['kind' => 'route.caddy_converge', 'node_id' => 2, 'input' => []]]);
    $child = footprintEvidenceChild('caddy', 'route.caddy_converge', 'failed', ['stage' => 'render'], 'caddy.render_failed');
    $child->forceFill(['child_fingerprint' => null]);

    expect(fn () => app(NodeFootprintEvidence::class)->derive($operation, [$child]))->toThrow(ResourceOperationException::class);
});

it('keeps the canonical Caddy child publication outcome in its artifact result', function (string $publication): void {
    $operation = footprintEvidenceOperation([footprintEvidenceArtifact('caddy')], ['caddy' => ['kind' => 'route.caddy_converge', 'node_id' => 2, 'input' => []]]);
    $child = footprintEvidenceChild('caddy', 'route.caddy_converge', result: ['ready' => true, 'result' => $publication]);

    $derived = app(NodeFootprintEvidence::class)->derive($operation, [$child]);

    expect($derived['result']['artifacts'])->toBe([['name' => 'caddy', 'status' => $publication === 'unchanged' ? 'unchanged' : 'applied', 'reason' => null]]);
    expect($derived['result']['changed'])->toBe($publication === 'published');
})->with(['published', 'unchanged']);

it('requires every PHP receipt before recording the runtime digest', function (string $variant): void {
    $operation = footprintEvidenceOperation([footprintEvidenceArtifact('php-runtime'), footprintEvidenceArtifact('tmpfiles', false)], recorded: ['php-runtime' => 'old', 'tmpfiles' => 'retained']);
    $input = $operation->input;
    $input['_footprint']['payloads']['php-runtime']['configurations'] = [['version' => '8.4'], ['version' => '8.5']];
    $operation->input = $input;
    $run = ['transport' => 'agent', 'name' => 'php.configure-runtime', 'result' => ['changed' => false]];
    $runs = ['footprint-php-runtime-8.4' => $run, 'footprint-php-runtime-8.5' => $run];
    if ($variant === 'changed') {
        $runs['footprint-php-runtime-8.4']['result']['changed'] = true;
    } elseif ($variant === 'failed') {
        $runs['footprint-php-runtime-8.5']['result'] = ['error_code' => 'php.runtime_configuration_failed'];
    } elseif ($variant === 'missing') {
        unset($runs['footprint-php-runtime-8.5']);
    } elseif ($variant === 'wrong-command') {
        $runs['footprint-php-runtime-8.5']['name'] = 'php.install-build';
    } elseif ($variant === 'wrong-transport') {
        $runs['footprint-php-runtime-8.5']['transport'] = 'gateway';
    } elseif ($variant === 'unproven') {
        $runs['footprint-php-runtime-8.5']['result'] = [];
    }
    $operation->state = ['executor_runs' => $runs];
    if (in_array($variant, ['missing', 'wrong-command', 'wrong-transport', 'unproven'], true)) {
        expect(fn () => app(NodeFootprintEvidence::class)->derive($operation, []))->toThrow(ResourceOperationException::class);
        if ($variant === 'missing') {
            expect(app(NodeFootprintEvidence::class)->derive($operation, [], true)['recorded'])->toBe(['php-runtime' => 'old', 'tmpfiles' => 'retained']);
        }

        return;
    }
    $derived = app(NodeFootprintEvidence::class)->derive($operation, []);
    if ($variant === 'failed') {
        expect($derived['error_code'])->toBe('php.runtime_configuration_failed')->and($derived['recorded'])->toBe(['tmpfiles' => 'retained']);
    } else {
        expect($derived['error_code'])->toBeNull()->and($derived['recorded']['php-runtime'])->toBe('digest-php-runtime')
            ->and($derived['result']['artifacts'][0])->toBe(['name' => 'php-runtime', 'status' => $variant === 'changed' ? 'applied' : 'unchanged', 'reason' => null]);
    }
})->with(['unchanged', 'changed', 'failed', 'missing', 'wrong-command', 'wrong-transport', 'unproven']);
