<?php

declare(strict_types=1);

use App\Enums\Apps\DependencyAuditStatus;
use App\Services\Apps\DependencyAudit\ComposerDependencyAuditParser;
use App\Services\Apps\DependencyAudit\DependencyAuditSeverityBands;
use App\Services\Apps\DependencyAudit\NpmDependencyAuditParser;

it('normalizes composer audit findings and compact advisory summaries', function (): void {
    $json = json_encode([
        'advisories' => [
            'laravel/framework' => [
                [
                    'advisoryId' => 'PKSA-aaaa-bbbb',
                    'packageName' => 'laravel/framework',
                    'severity' => 'high',
                    'title' => 'High framework advisory',
                    'cve' => 'CVE-2026-0001',
                    'link' => 'https://example.test/advisories/1',
                    'ignored' => '',
                ],
                [
                    'advisoryId' => 'PKSA-cccc-dddd',
                    'packageName' => 'laravel/framework',
                    'severity' => 'medium',
                    'title' => 'Medium framework advisory',
                ],
            ],
            'symfony/http-foundation' => [
                [
                    'advisoryId' => 'PKSA-eeee-ffff',
                    'packageName' => 'symfony/http-foundation',
                    'severity' => 'low',
                    'title' => 'Low foundation advisory',
                ],
                [
                    'advisoryId' => 'PKSA-gggg-hhhh',
                    'packageName' => 'symfony/http-foundation',
                    'severity' => null,
                    'title' => 'Unknown foundation advisory',
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $result = new ComposerDependencyAuditParser(new DependencyAuditSeverityBands)->parse($json, 1);

    expect($result->status)
        ->toBe(DependencyAuditStatus::Findings)
        ->and($result->dangerCount)
        ->toBe(1)
        ->and($result->warningCount)
        ->toBe(3)
        ->and($result->severityCounts)
        ->toBe([
            'high' => 1,
            'medium' => 1,
            'low' => 1,
            'unknown' => 1,
        ])
        ->and($result->advisorySummary)
        ->toHaveCount(4)
        ->and($result->advisorySummary[0])
        ->toBe([
            'advisory_id' => 'PKSA-aaaa-bbbb',
            'package_name' => 'laravel/framework',
            'severity' => 'high',
            'title' => 'High framework advisory',
            'cve' => 'CVE-2026-0001',
            'link' => 'https://example.test/advisories/1',
        ])
        ->and($result->advisorySummary[3])
        ->toBe([
            'advisory_id' => 'PKSA-gggg-hhhh',
            'package_name' => 'symfony/http-foundation',
            'title' => 'Unknown foundation advisory',
        ]);
});

it('normalizes npm audit metadata severity counts', function (): void {
    $json = json_encode([
        'metadata' => [
            'vulnerabilities' => [
                'critical' => 1,
                'high' => 2,
                'moderate' => 3,
            ],
        ],
        'vulnerabilities' => [
            'vite' => [
                'name' => 'vite',
                'severity' => 'high',
                'isDirect' => true,
                'range' => '<5.0.0',
                'fixAvailable' => true,
                'via' => [
                    [
                        'source' => 123,
                        'name' => 'vite',
                        'severity' => 'high',
                        'title' => 'Vite advisory',
                    ],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $result = new NpmDependencyAuditParser(new DependencyAuditSeverityBands)->parse($json, 1);

    expect($result->status)
        ->toBe(DependencyAuditStatus::Findings)
        ->and($result->dangerCount)
        ->toBe(3)
        ->and($result->warningCount)
        ->toBe(3)
        ->and($result->severityCounts)
        ->toBe([
            'critical' => 1,
            'high' => 2,
            'moderate' => 3,
        ])
        ->and($result->advisorySummary)
        ->toBe([
            [
                'name' => 'vite',
                'severity' => 'high',
                'is_direct' => true,
                'range' => '<5.0.0',
                'fix_available' => true,
                'via' => [
                    [
                        'source' => 123,
                        'name' => 'vite',
                        'severity' => 'high',
                        'title' => 'Vite advisory',
                    ],
                ],
            ],
        ]);
});

it('falls back to npm vulnerability severities and treats null as unknown warning', function (): void {
    $json = json_encode([
        'vulnerabilities' => [
            'left-pad' => [
                'name' => 'left-pad',
                'severity' => null,
                'via' => [],
            ],
            'minimist' => [
                'name' => 'minimist',
                'severity' => 'unknown',
                'via' => [],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $result = new NpmDependencyAuditParser(new DependencyAuditSeverityBands)->parse($json, 1);

    expect($result->status)
        ->toBe(DependencyAuditStatus::Findings)
        ->and($result->dangerCount)
        ->toBe(0)
        ->and($result->warningCount)
        ->toBe(2)
        ->and($result->severityCounts)
        ->toBe([
            'unknown' => 2,
        ]);
});
