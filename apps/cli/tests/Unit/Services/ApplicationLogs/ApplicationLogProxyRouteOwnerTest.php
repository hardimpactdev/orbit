<?php

declare(strict_types=1);

use App\Services\ApplicationLogs\ApplicationLogProxyRouteOwner;
use App\Services\ApplicationLogs\ApplicationLogProxyWorkspaceOwner;

it('accepts a canonical workspace slug', function (): void {
    $result = new ApplicationLogProxyWorkspaceOwner()->resolve('feature.example.test', 'feature-docs', [
        'instance' => 'docs.preview',
    ]);

    expect($result)->toBe([
        'ok' => true,
        'type' => 'workspace',
        'workspace' => 'feature-docs',
        'instance' => 'docs.preview',
    ]);
});

it('rejects a non-canonical workspace slug', function (string $workspace): void {
    $result = new ApplicationLogProxyWorkspaceOwner()->resolve('feature.example.test', $workspace, [
        'instance' => 'docs.preview',
    ]);

    expect($result)->toMatchArray([
        'ok' => false,
        'field' => 'target',
        'meta' => [
            'workspace' => $workspace,
            'host' => 'feature.example.test',
        ],
    ]);
})->with([
    'dotted' => 'feature.docs',
    'uppercase' => 'Feature-docs',
    'underscore' => 'feature_docs',
    'empty' => '',
    'too long' => str_repeat('a', 64),
    'leading hyphen' => '-feature-docs',
    'trailing hyphen' => 'feature-docs-',
    'reserved main' => 'main',
]);

it('rejects malformed instance owner selectors', function (string $selector): void {
    $result = new ApplicationLogProxyRouteOwner()->resolve('docs.example.test', [
        'owner' => ['type' => 'instance', 'name' => $selector],
    ]);

    expect($result)
        ->toMatchArray([
            'ok' => false,
            'field' => 'target',
            'meta' => ['host' => 'docs.example.test'],
        ]);
})->with([
    'no dot' => 'docs',
    'multiple dots' => 'docs.preview.extra',
    'uppercase' => 'Docs.preview',
    'empty app' => '.preview',
    'empty instance' => 'docs.',
    'underscore' => 'docs.pre_view',
]);

it('rejects malformed workspace parent instance selectors', function (string $selector): void {
    $result = new ApplicationLogProxyRouteOwner()->resolve('feature.example.test', [
        'owner' => ['type' => 'workspace', 'name' => 'feature-docs'],
        'instance' => $selector,
    ]);

    expect($result)
        ->toMatchArray([
            'ok' => false,
            'field' => 'instance',
            'meta' => [
                'workspace' => 'feature-docs',
                'host' => 'feature.example.test',
            ],
        ]);
})->with([
    'no dot' => 'docs',
    'multiple dots' => 'docs.preview.extra',
    'uppercase' => 'Docs.preview',
    'empty app' => '.preview',
    'empty instance' => 'docs.',
    'underscore' => 'docs.pre_view',
]);
