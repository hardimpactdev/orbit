<?php

declare(strict_types=1);

describe('activity:show', function (): void {
    it('returns a canonical success envelope in JSON mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'activity' => ['id' => 42, 'effect' => 'write', 'command' => 'app:new'],
            'related' => [],
        ], ['related_count' => 0]));

        [$exitCode, $output] = runCommand($this, 'activity:show', ['id' => '42', '--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($decoded)->toHaveKey('success')
            ->and($decoded['success']['data']['activity'])->toHaveKey('id')
            ->and($decoded['success']['meta']['related_count'])->toBe(0);
    });

    it('renders human output containing activity id', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'activity' => ['id' => 42, 'effect' => 'write', 'command' => 'app:new'],
            'related' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'activity:show', ['id' => '42']);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('"id":42');
    });

    it('surfaces the error code on a gateway 500', function (): void {
        fakeGateway(fakeErrorEnvelope('not_found', 'Not found.'), 500);

        [$exitCode, $output] = runCommand($this, 'activity:show', ['id' => '42', '--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unavailable');
    });

    it('surfaces gateway_unavailable when the gateway is unreachable', function (): void {
        fakeGatewayDown();

        [$exitCode, $output] = runCommand($this, 'activity:show', ['id' => '42', '--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unavailable');
    });
});
