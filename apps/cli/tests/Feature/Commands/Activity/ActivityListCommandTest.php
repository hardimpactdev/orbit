<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('activity:list', function (): void {
    it('returns a canonical success envelope in JSON mode', function (): void {
        fakeGateway(fakeSuccessEnvelope(['activities' => [['id' => 1, 'effect' => 'read']]]));

        [$exitCode, $output] = runCommand($this, 'activity:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($decoded)->toHaveKey('success')
            ->and($decoded['success'])->toHaveKey('data');
    });

    it('forwards filters and preserves gateway metadata in JSON mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'activities' => [
                [
                    'id' => 42,
                    'effect' => 'destructive',
                    'subject' => ['type' => 'app', 'name' => 'docs'],
                    'summary' => 'Removed app docs.',
                ],
            ],
        ], [
            'filters' => [
                'effect' => 'destructive',
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'activity:list', [
            '--effect' => 'destructive',
            '--limit' => '50',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'GET'
                && str_contains($url, '/api/activity')
                && str_contains($url, 'effect=destructive')
                && str_contains($url, 'limit=50');
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['activities'][0]['id'])->toBe(42)
            ->and($decoded['success']['data']['activities'][0]['effect'])->toBe('destructive')
            ->and($decoded['success']['data']['activities'][0]['subject'])->toBe(['type' => 'app', 'name' => 'docs'])
            ->and($decoded['success']['meta']['filters']['effect'])->toBe('destructive');
    });

    it('renders human output as a table with uppercase headers and newest-first rows', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'activities' => [
                [
                    'id' => 43,
                    'occurred_at' => '2026-05-02T08:31:12+00:00',
                    'correlation_id' => '9f7307e8-38b2-45b8-9b94-cfc341456b85',
                    'type' => 'node.removed',
                    'effect' => 'destructive',
                    'subject' => ['type' => 'node', 'name' => 'app-2'],
                    'actor' => ['node' => 'operator-1'],
                    'command' => 'node:remove',
                    'summary' => 'Removed node app-2.',
                ],
                [
                    'id' => 41,
                    'occurred_at' => '2026-05-02T08:29:58+00:00',
                    'correlation_id' => null,
                    'type' => 'node.list',
                    'effect' => 'read',
                    'subject' => null,
                    'actor' => null,
                    'command' => null,
                    'summary' => 'Listed nodes.',
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'activity:list');

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('TIME')
            ->and($output)->toContain('ID')
            ->and($output)->toContain('EFFECT')
            ->and($output)->toContain('TYPE')
            ->and($output)->toContain('SUBJECT')
            ->and($output)->toContain('ACTOR')
            ->and($output)->toContain('COMMAND')
            ->and($output)->toContain('2026-05-02 08:31:12')
            ->and($output)->toContain('destructive')
            ->and($output)->toContain('node.removed')
            ->and($output)->toContain('app-2')
            ->and($output)->toContain('operator-1')
            ->and($output)->toContain('node:remove')
            ->and($output)->toContain('—')
            ->and(mb_strpos($output, '43'))->toBeLessThan(mb_strpos($output, '41'))
            ->and($output)->not->toContain('activities: [')
            ->and($output)->not->toContain('"summary"');
    });

    it('renders empty human output without a JSON envelope', function (): void {
        fakeGateway(fakeSuccessEnvelope(['activities' => []]));

        [$exitCode, $output] = runCommand($this, 'activity:list');

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('No activity found.')
            ->and($output)->not->toContain('"success"');
    });

    it('rejects invalid filters before opening a gateway request', function (array $arguments, string $field, string $reason): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'activity:list', [
            ...$arguments,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['message'])->toBe('Invalid activity filter.')
            ->and($decoded['error']['meta'])->toBe([
                'field' => $field,
                'reason' => $reason,
            ]);
    })->with([
        'unsupported effect' => [['--effect' => 'nope'], 'effect', 'unsupported_value'],
        'out of range limit' => [['--limit' => '201'], 'limit', 'out_of_range'],
        'invalid correlation' => [['--correlation' => 'not-a-uuid'], 'correlation', 'invalid'],
    ]);

    it('surfaces the gateway error code on a gateway 500', function (): void {
        fakeGateway(fakeErrorEnvelope('internal_error', 'Server failure.'), 500);

        [$exitCode, $output] = runCommand($this, 'activity:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded)->toHaveKey('error')
            ->and($decoded['error']['code'])->toBe('internal_error')
            ->and($decoded['error']['message'])->toBe('Server failure.');
    });

    it('surfaces gateway_unavailable when the gateway is unreachable', function (): void {
        fakeGatewayDown();

        [$exitCode, $output] = runCommand($this, 'activity:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unavailable');
    });
});
