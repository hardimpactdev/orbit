<?php

declare(strict_types=1);

use App\Services\ApplicationLogs\ApplicationLogProxyRouteOwner;

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
        'owner' => ['type' => 'workspace', 'name' => 'feature.docs'],
        'instance' => $selector,
    ]);

    expect($result)
        ->toMatchArray([
            'ok' => false,
            'field' => 'instance',
            'meta' => [
                'workspace' => 'feature.docs',
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
