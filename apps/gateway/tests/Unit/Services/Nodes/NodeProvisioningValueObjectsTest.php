<?php

declare(strict_types=1);

use App\Services\Nodes\NodeCreationIngressPlacement;
use App\Services\Nodes\NodeCreationInput;
use App\Services\Nodes\WorkloadNodeProvisioningInput;

it('reads node creation arguments without mutable service state', function (): void {
    $input = new NodeCreationInput([
        'name' => 'app-1',
        '--host' => '192.0.2.20',
        '--agent-tool' => ['claude', '', 42],
        '--user' => '',
    ]);

    expect($input->stringArgument('name'))
        ->toBe('app-1')
        ->and($input->stringOption('host'))
        ->toBe('192.0.2.20')
        ->and($input->arrayOption('agent-tool'))
        ->toBe(['claude'])
        ->and($input->stringOption('user'))
        ->toBeNull()
        ->and($input->optionWasSupplied('user'))
        ->toBeTrue()
        ->and($input->option('missing'))
        ->toBeNull();
});

it('holds resolved workload provisioning input without magic array keys', function (): void {
    $input = new WorkloadNodeProvisioningInput(
        host: '192.0.2.20',
        tld: 'app-one',
        sshUser: 'root',
        gatewayEndpoint: '198.51.100.10',
        hostKeyFingerprint: 'SHA256:example',
        platform: 'ubuntu_24-04',
        architecture: 'amd64',
        postgresNodeId: 11,
        postgresProcessId: 12,
        clickhouseNodeId: 13,
        s3DataPath: '/srv/orbit/s3/data',
    );

    expect($input->host)
        ->toBe('192.0.2.20')
        ->and($input->tld)
        ->toBe('app-one')
        ->and($input->sshUser)
        ->toBe('root')
        ->and($input->gatewayEndpoint)
        ->toBe('198.51.100.10')
        ->and($input->hostKeyFingerprint)
        ->toBe('SHA256:example')
        ->and($input->platform)
        ->toBe('ubuntu_24-04')
        ->and($input->architecture)
        ->toBe('amd64')
        ->and($input->postgresNodeId)
        ->toBe(11)
        ->and($input->postgresProcessId)
        ->toBe(12)
        ->and($input->clickhouseNodeId)
        ->toBe(13)
        ->and($input->s3DataPath)
        ->toBe('/srv/orbit/s3/data');
});

it('holds ordered roles and the resolved private ingress node', function (): void {
    $placement = new NodeCreationIngressPlacement(
        roles: ['ingress', 'app-prod'],
        ingressNodeId: 42,
        ingressNodeName: 'edge-1',
    );

    expect($placement->roles)
        ->toBe(['ingress', 'app-prod'])
        ->and($placement->ingressNodeId)
        ->toBe(42)
        ->and($placement->ingressNodeName)
        ->toBe('edge-1');
});
