<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

describe('activity:show', function (): void {
    it('returns a canonical success envelope in JSON mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'activity' => ['id' => 42, 'effect' => 'write', 'command' => 'app:new'],
            'related' => [],
        ], ['related_count' => 0]));

        [$exitCode, $output] = runCommand($this, 'activity:show', ['id' => '42', '--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($decoded)
            ->toHaveKey('success')
            ->and($decoded['success']['data']['activity'])
            ->toHaveKey('id')
            ->and($decoded['success']['meta']['related_count'])
            ->toBe(0);
    });

    it('renders human output containing activity id', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'activity' => [
                'id' => 42,
                'occurred_at' => '2026-05-02T08:30:00+00:00',
                'type' => 'workspace.created',
                'effect' => 'write',
                'command' => 'app:new',
                'description' => 'Workspace created',
                'properties' => ['workspace' => 'feature/docs'],
                'channel' => 'api',
            ],
            'related' => [],
        ]));

        [$exitCode, $output] = runCommand($this, command: 'activity:show', params: ['id' => '42']);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Activity: 42')
            ->and($output)
            ->toContain('Type')
            ->and($output)
            ->toContain('workspace.created')
            ->and($output)
            ->toContain('Effect')
            ->and($output)
            ->toContain('write')
            ->and($output)
            ->toContain('Description')
            ->and($output)
            ->toContain('Workspace created')
            ->and($output)
            ->toContain('Properties')
            ->and($output)
            ->toContain('workspace: feature/docs')
            ->and($output)
            ->toContain('Channel')
            ->and($output)
            ->toContain('api')
            ->and($output)
            ->not->toContain('Summary')->and($output)
            ->not->toContain('Details');
    });

    it('returns validation_failed when id is missing', function (): void {
        [$exitCode, $output] = runCommand($this, 'activity:show', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('id')
            ->and($decoded['error']['meta']['reason'])
            ->toBe('missing');
    });

    it('returns validation_failed when id is invalid', function (): void {
        [$exitCode, $output] = runCommand($this, 'activity:show', ['id' => 'nope', '--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('id')
            ->and($decoded['error']['meta']['reason'])
            ->toBe('invalid');
    });

    it('returns validation_failed when id is zero before opening a gateway request', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'activity:show', ['id' => '0', '--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['message'])
            ->toBe('Activity id must be a positive integer.')
            ->and($decoded['error']['meta'])
            ->toBe([
                'field' => 'id',
                'reason' => 'invalid',
            ]);
    });

    it('surfaces gateway error envelopes without replacing the error code', function (): void {
        fakeGateway(fakeErrorEnvelope('activity_not_found', 'Activity 42 was not found or is not visible.', [
            'id' => 42,
        ]), 404);

        [$exitCode, $output] = runCommand($this, 'activity:show', ['id' => '42', '--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('activity_not_found')
            ->and($decoded['error']['meta']['id'])
            ->toBe(42);
    });

    it('preserves authorization failures from the gateway', function (): void {
        fakeGateway(
            fakeErrorEnvelope('authorization_failed', 'This node is not authorized to read gateway activity history.'),
            403,
        );

        [$exitCode, $output] = runCommand($this, 'activity:show', ['id' => '42', '--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('authorization_failed')
            ->and($decoded['error']['message'])
            ->toBe('This node is not authorized to read gateway activity history.');
    });

    it('surfaces gateway_unavailable when the gateway is unreachable', function (): void {
        fakeGatewayDown();

        [$exitCode, $output] = runCommand($this, 'activity:show', ['id' => '42', '--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('gateway_unavailable');
    });

    it('returns gateway_unavailable in human mode when activity data is missing from a success envelope', function (): void {
        fakeGateway(fakeSuccessEnvelope(['related' => []]));

        [$exitCode, $output] = runCommand($this, command: 'activity:show', params: ['id' => '42']);

        expect($exitCode)
            ->toBe(1)
            ->and($output)
            ->toContain('gateway_unavailable: Gateway response missing required activity data.')
            ->and($output)
            ->not->toContain('"success"');
    });

    it('renders related activity entries in human output', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'activity' => [
                'id' => 42,
                'occurred_at' => '2026-05-02T08:30:00+00:00',
                'type' => 'workspace.created',
                'effect' => 'write',
                'command' => 'workspace:setup',
            ],
            'related' => [
                [
                    'id' => 41,
                    'occurred_at' => '2026-05-02T08:29:58+00:00',
                    'type' => 'workspace.create_requested',
                    'effect' => 'read',
                ],
            ],
        ], ['related_count' => 1]));

        [$exitCode, $output] = runCommand($this, command: 'activity:show', params: ['id' => '42']);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Related')
            ->and($output)
            ->toContain('41')
            ->and($output)
            ->toContain('workspace.create_requested')
            ->and($output)
            ->toContain('read');
    });

    it('skips non-array related entries when parsing gateway success data for human output', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'activity' => [
                'id' => 42,
                'occurred_at' => '2026-05-02T08:30:00+00:00',
                'type' => 'workspace.created',
                'effect' => 'write',
            ],
            'related' => [
                [
                    'id' => 41,
                    'occurred_at' => '2026-05-02T08:29:58+00:00',
                    'type' => 'workspace.create_requested',
                    'effect' => 'read',
                ],
                'skip-me',
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'activity:show', ['id' => '42']);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('41')
            ->and($output)
            ->not->toContain('skip-me');
    });

    it('passes malformed gateway success data through unchanged in JSON mode', function (): void {
        fakeGateway(fakeSuccessEnvelope(['activity' => 42, 'related' => 'nope']));

        [$exitCode, $output] = runCommand($this, command: 'activity:show', params: ['id' => '42', '--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['activity'])
            ->toBe(42)
            ->and($decoded['success']['data']['related'])
            ->toBe('nope');
    });
});
