<?php

declare(strict_types=1);

use App\Services\Doctor\DoctorPublicVocabulary;

it('apps private doctor app vocabulary to the public app and instance contract', function (): void {
    $report = app(DoctorPublicVocabulary::class)->publicReport([
        'scope' => [
            'families' => ['node', 'app'],
            'app' => 'docs',
            'instance' => 'production',
        ],
        'issues' => [[
            'family' => 'app',
            'key' => 'app.path_missing',
            'code' => 'app.path_missing',
            'detail' => [
                'app' => 'docs',
                'instance' => 'production',
                'target_type' => 'instance',
            ],
        ]],
    ]);

    expect($report['scope'])
        ->toBe([
            'families' => ['node', 'instance'],
            'app' => 'docs',
            'instance' => 'production',
        ])
        ->and($report['issues'][0])
        ->toMatchArray([
            'family' => 'instance',
            'key' => 'instance.path_missing',
            'code' => 'instance.path_missing',
            'detail' => [
                'app' => 'docs',
                'instance' => 'production',
                'target_type' => 'instance',
            ],
        ]);
});

it('maps public doctor selections back to private probe vocabulary', function (): void {
    $vocabulary = app(DoctorPublicVocabulary::class);

    expect($vocabulary->internalFamilies(['node', 'instance']))
        ->toBe(['node', 'app'])
        ->and($vocabulary->internalKey('instance.path_missing'))
        ->toBe('app.path_missing')
        ->and($vocabulary->internalIssues([[
            'family' => 'instance',
            'key' => 'instance.path_missing',
            'detail' => [
                'app' => 'docs',
                'instance' => 'production',
                'target_type' => 'instance',
            ],
        ]]))
        ->toBe([[
            'family' => 'app',
            'key' => 'app.path_missing',
            'detail' => [
                'app' => 'docs',
                'instance' => 'production',
                'target_type' => 'instance',
            ],
        ]]);
});
