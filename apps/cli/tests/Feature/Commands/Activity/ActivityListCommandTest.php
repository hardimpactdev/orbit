<?php

declare(strict_types=1);

describe('activity:list', function (): void {
    it('returns a canonical success envelope in JSON mode', function (): void {
        fakeGateway(fakeSuccessEnvelope(['activities' => [['id' => 1, 'effect' => 'read']]]));

        [$exitCode, $output] = runCommand($this, 'activity:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($decoded)->toHaveKey('success')
            ->and($decoded['success'])->toHaveKey('data');
    });

    it('renders human output containing activity fields', function (): void {
        fakeGateway(fakeSuccessEnvelope(['activities' => [['id' => 1, 'effect' => 'read']]]));

        [$exitCode, $output] = runCommand($this, 'activity:list');

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('activities');
    });

    it('surfaces the error code on a gateway 500', function (): void {
        fakeGateway(fakeErrorEnvelope('internal_error', 'Server failure.'), 500);

        [$exitCode, $output] = runCommand($this, 'activity:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded)->toHaveKey('error')
            ->and($decoded['error']['code'])->toBe('gateway_unavailable');
    });

    it('surfaces gateway_unavailable when the gateway is unreachable', function (): void {
        fakeGatewayDown();

        [$exitCode, $output] = runCommand($this, 'activity:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unavailable');
    });
});
