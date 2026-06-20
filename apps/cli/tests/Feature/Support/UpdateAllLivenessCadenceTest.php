<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/Support/update_all_liveness_cadence.php';

it('rejects spinner transitions that arrive before the cadence floor', function (): void {
    $result = validateUpdateAllLivenessCadence(100_000);

    expect($result['cadence_ok'])->toBeFalse()
        ->and($result['reason'])->toContain('before')
        ->and($result['first_transition_us'])->toBe(100_000);
});

it('accepts spinner transitions that arrive around the 300ms cadence window', function (): void {
    $result = validateUpdateAllLivenessCadence(320_000);

    expect($result['cadence_ok'])->toBeTrue()
        ->and($result['first_transition_us'])->toBe(320_000);
});

it('records the first stable-row transition from polling observations', function (): void {
    $state = [
        'anchor_us' => null,
        'anchor_spinner' => null,
        'first_transition_us' => -1,
        'last_spinner' => null,
    ];

    $anchorUs = 1_000_000_000;
    updateAllLivenessObserveSpinner($state, 'cyan-open', $anchorUs);
    updateAllLivenessObserveSpinner($state, 'cyan-filled', $anchorUs + 300_000);

    $result = validateUpdateAllLivenessCadence($state['first_transition_us']);

    expect($result['cadence_ok'])->toBeTrue()
        ->and($state['first_transition_us'])->toBe(300_000);
});
