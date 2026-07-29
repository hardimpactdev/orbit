<?php

declare(strict_types=1);

use Orbit\Core\Php\PhpCliRuntimeClassifier;
use Orbit\Core\Php\PhpCliVariant;

it('classifies ordinary standard PHP', function (): void {
    $result = new PhpCliRuntimeClassifier()->classify(PhpCliVariant::Standard, [
        'present' => true,
        'patch' => '8.5.8',
        'expected_patch' => '8.5.8',
        'extension_loaded_pcov' => false,
        'function_exists_pcov_start' => false,
        'pcov_enabled' => false,
        'ri_pcov_ok' => false,
    ]);

    expect($result['kind'])->toBe(PhpCliRuntimeClassifier::KIND_STANDARD)->and($result['ok'])->toBeTrue();
});

it('rejects standard PHP when any PCOV surface is present', function (array $observed): void {
    $result = new PhpCliRuntimeClassifier()->classify(PhpCliVariant::Standard, [
        'present' => true,
        'patch' => '8.5.8',
        'expected_patch' => '8.5.8',
        'extension_loaded_pcov' => false,
        'function_exists_pcov_start' => false,
        'pcov_enabled' => false,
        'ri_pcov_ok' => false,
        ...$observed,
    ]);

    expect($result['kind'])
        ->toBe(PhpCliRuntimeClassifier::KIND_COVERAGE)
        ->and($result['ok'])
        ->toBeFalse()
        ->and($result['summary'])
        ->toContain('PCOV');
})->with([
    'extension loaded' => [[
        'extension_loaded_pcov' => true,
    ]],
    'function exists' => [[
        'function_exists_pcov_start' => true,
    ]],
    'pcov enabled' => [[
        'pcov_enabled' => true,
    ]],
    'ri pcov ok' => [[
        'ri_pcov_ok' => true,
    ]],
    'full coverage binary' => [[
        'extension_loaded_pcov' => true,
        'function_exists_pcov_start' => true,
        'pcov_enabled' => true,
        'ri_pcov_ok' => true,
    ]],
]);

it('classifies standard patch mismatch when PCOV is entirely absent', function (): void {
    $result = new PhpCliRuntimeClassifier()->classify(PhpCliVariant::Standard, [
        'present' => true,
        'patch' => '8.5.7',
        'expected_patch' => '8.5.8',
        'extension_loaded_pcov' => false,
        'function_exists_pcov_start' => false,
        'pcov_enabled' => false,
        'ri_pcov_ok' => false,
    ]);

    expect($result['kind'])
        ->toBe(PhpCliRuntimeClassifier::KIND_STANDARD)
        ->and($result['ok'])
        ->toBeFalse()
        ->and($result['summary'])
        ->toContain('patch');
});

it('classifies valid coverage PHP', function (): void {
    $result = new PhpCliRuntimeClassifier()->classify(PhpCliVariant::Coverage, [
        'present' => true,
        'patch' => '8.5.8',
        'expected_patch' => '8.5.8',
        'extension_loaded_pcov' => true,
        'function_exists_pcov_start' => true,
        'pcov_enabled' => true,
        'ri_pcov_ok' => true,
    ]);

    expect($result['kind'])->toBe(PhpCliRuntimeClassifier::KIND_COVERAGE)->and($result['ok'])->toBeTrue();
});

it('classifies coverage requested but PCOV broken', function (array $observed): void {
    $result = new PhpCliRuntimeClassifier()->classify(PhpCliVariant::Coverage, [
        'present' => true,
        'patch' => '8.5.8',
        'expected_patch' => '8.5.8',
        ...$observed,
    ]);

    expect($result['kind'])->toBe(PhpCliRuntimeClassifier::KIND_COVERAGE_BROKEN)->and($result['ok'])->toBeFalse();
})->with([
    'pcov missing' => [[
        'extension_loaded_pcov' => false,
        'function_exists_pcov_start' => false,
        'pcov_enabled' => false,
        'ri_pcov_ok' => false,
    ]],
    'pcov disabled' => [[
        'extension_loaded_pcov' => true,
        'function_exists_pcov_start' => true,
        'pcov_enabled' => false,
        'ri_pcov_ok' => true,
    ]],
    'start missing' => [[
        'extension_loaded_pcov' => true,
        'function_exists_pcov_start' => false,
        'pcov_enabled' => true,
        'ri_pcov_ok' => true,
    ]],
    'ri fails' => [[
        'extension_loaded_pcov' => true,
        'function_exists_pcov_start' => true,
        'pcov_enabled' => true,
        'ri_pcov_ok' => false,
    ]],
]);

it('classifies missing binaries', function (): void {
    $result = new PhpCliRuntimeClassifier()->classify(PhpCliVariant::Coverage, [
        'present' => false,
    ]);

    expect($result['kind'])->toBe(PhpCliRuntimeClassifier::KIND_MISSING)->and($result['ok'])->toBeFalse();
});
