<?php

declare(strict_types=1);

const UPDATE_ALL_LIVENESS_MIN_TRANSITION_US = 250_000;

function updateAllLivenessCadenceFloorUs(): int
{
    $configured = getenv('ORBIT_UPDATE_ALL_LIVENESS_MIN_TRANSITION_US');

    if (is_string($configured) && $configured !== '' && ctype_digit($configured)) {
        return (int) $configured;
    }

    return UPDATE_ALL_LIVENESS_MIN_TRANSITION_US;
}

function updateAllLivenessNowUs(): int
{
    return intdiv(hrtime(true), 1000);
}

/**
 * @param  array{anchor_us: int|null, anchor_spinner: string|null, first_transition_us: int, last_spinner: string|null}  $state
 */
function updateAllLivenessObserveSpinner(array &$state, string $spinner, int $nowUs): void
{
    if ($state['anchor_us'] === null) {
        $state['anchor_us'] = $nowUs;
        $state['anchor_spinner'] = $spinner;
        $state['last_spinner'] = $spinner;

        return;
    }

    if ($spinner !== $state['anchor_spinner'] && $state['first_transition_us'] < 0) {
        $state['first_transition_us'] = $nowUs - $state['anchor_us'];
    }

    $state['last_spinner'] = $spinner;
}

/**
 * @return array{cadence_ok: bool, reason: string|null, first_transition_us: int|null}
 */
function validateUpdateAllLivenessCadence(int $firstTransitionUs): array
{
    if ($firstTransitionUs < 0) {
        return [
            'cadence_ok' => false,
            'reason' => 'no spinner transition observed on stable row',
            'first_transition_us' => null,
        ];
    }

    $minUs = updateAllLivenessCadenceFloorUs();

    if ($firstTransitionUs < $minUs) {
        return [
            'cadence_ok' => false,
            'reason' => "first transition at {$firstTransitionUs}us is before {$minUs}us cadence floor",
            'first_transition_us' => $firstTransitionUs,
        ];
    }

    return [
        'cadence_ok' => true,
        'reason' => null,
        'first_transition_us' => $firstTransitionUs,
    ];
}
