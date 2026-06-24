<?php

declare(strict_types=1);

use App\Commands\Activity\ActivityGatewayResponse;
use Orbit\Core\Http\JsonEnvelope;

describe('ActivityGatewayResponse', function (): void {
    it('extracts activity rows from a gateway success envelope', function (): void {
        $response = JsonEnvelope::success([
            'activities' => [
                ['id' => 1, 'effect' => 'read'],
                'skip-me',
                ['id' => 2, 'effect' => 'write'],
            ],
        ]);

        expect(ActivityGatewayResponse::activitiesFrom($response))
            ->toBe([
                ['id' => 1, 'effect' => 'read'],
                ['id' => 2, 'effect' => 'write'],
            ]);
    });

    it('returns an empty list when activities is not an array', function (): void {
        $response = JsonEnvelope::success(['activities' => 'nope']);

        expect(ActivityGatewayResponse::activitiesFrom($response))->toBeEmpty();
    });

    it('detects an explicit empty activities collection', function (): void {
        $response = JsonEnvelope::success(['activities' => []]);

        expect(ActivityGatewayResponse::hasNoActivities($response))->toBeTrue();
    });

    it('does not treat a missing activities key as an explicit empty collection', function (): void {
        $response = JsonEnvelope::success([]);

        expect(ActivityGatewayResponse::hasNoActivities($response))->toBeFalse();
    });

    it('extracts a single activity record', function (): void {
        $response = JsonEnvelope::success([
            'activity' => ['id' => 42, 'effect' => 'write', 'command' => 'app:new'],
        ]);

        expect(ActivityGatewayResponse::activityFrom($response))
            ->toBe(['id' => 42, 'effect' => 'write', 'command' => 'app:new']);
    });

    it('returns null when activity is not an array', function (): void {
        $response = JsonEnvelope::success(['activity' => 42]);

        expect(ActivityGatewayResponse::activityFrom($response))->toBeNull();
    });

    it('extracts related rows and skips invalid entries', function (): void {
        $response = JsonEnvelope::success([
            'activity' => ['id' => 42],
            'related' => [
                [
                    'id' => 41,
                    'occurred_at' => '2026-05-02T08:29:58+00:00',
                    'type' => 'workspace.create_requested',
                    'effect' => 'read',
                ],
                null,
                'skip-me',
            ],
        ]);

        expect(ActivityGatewayResponse::relatedFrom($response))
            ->toBe([
                [
                    'id' => 41,
                    'occurred_at' => '2026-05-02T08:29:58+00:00',
                    'type' => 'workspace.create_requested',
                    'effect' => 'read',
                ],
            ]);
    });

    it('returns an empty related list when related is not an array', function (): void {
        $response = JsonEnvelope::success([
            'activity' => ['id' => 42],
            'related' => 'nope',
        ]);

        expect(ActivityGatewayResponse::relatedFrom($response))->toBeEmpty();
    });
});
