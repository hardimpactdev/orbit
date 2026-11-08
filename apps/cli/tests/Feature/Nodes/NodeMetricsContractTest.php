<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\ShowNodeMetricsRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * Contract tests replay recorded Gateway responses and compare the complete command
 * output with tests/Expected. A fixture change that reaches a command fails here first.
 */
beforeEach(function (): void {
    $this->originalColumns = getenv('COLUMNS');
    putenv('COLUMNS=120');
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    putenv($this->originalColumns === false ? 'COLUMNS' : 'COLUMNS='.$this->originalColumns);
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

describe('node metrics contract', function (): void {
    it('renders node:metrics from the recorded response', function (): void {
        run_contract('nodes/node-metrics/default', 'node:metrics', ['node' => '1'], 'nodes/node-metrics/default.human.txt', 0);
        run_contract('nodes/node-metrics/default', 'node:metrics', ['node' => '1', '--json' => true], 'nodes/node-metrics/default.json', 0);
    });

    it('refuses invalid and omitted IDs locally without looking up names or contacting the Gateway', function (?string $id, bool $json): void {
        $mock = MockClient::global([]);
        $arguments = ['--json' => $json, '--no-interaction' => true];
        if ($id !== null) {
            $arguments['node'] = $id;
        }
        expect(Artisan::call('node:metrics', $arguments))->toBe(1);
        expect(Artisan::output())->toContain('node.id_invalid');
        $mock->assertNothingSent();
    })->with([null, '0', '-1', '999999999999999999999999999999', 'not-a-node-id', '1.5', '--not-a-node-id'])->with([true, false]);

    it('shows retained snapshots with their observation time and shared agent wording', function (?array $agent, string $label): void {
        $body = json_decode(gateway_fixture('nodes/node-metrics/default')['body'], true, flags: JSON_THROW_ON_ERROR);
        $body['data']['agent'] = $agent;
        $body['data']['possibly_outdated'] = true;
        MockClient::global([ShowNodeMetricsRequest::class => MockResponse::make($body)]);
        expect(Artisan::call('node:metrics', ['node' => '1']))->toBe(0);
        expect(Artisan::output())->toContain('Node app-dev metrics', 'Observed at', '2026-11-06T10:00:00Z', $label, 'possibly outdated');
        expect(Artisan::call('node:metrics', ['node' => '1', '--json' => true]))->toBe(0);
        $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($output['observed_at'])->toBe('2026-11-06T10:00:00Z')
            ->and($output['agent'])->toBe($agent)->and($output['possibly_outdated'])->toBeTrue();
    })->with([
        'offline' => [['state' => 'offline', 'since' => '2026-11-06T10:00:10Z'], 'offline since 2026-11-06T10:00:10Z'],
        'not seen' => [['state' => 'not_seen', 'since' => null], 'not seen'],
        'unknown' => [['state' => 'unknown', 'since' => null], 'unknown'],
        'absent' => [null, '—'],
    ]);

    it('renders snapshot and authority refusals without inventing readings', function (string $code, int $status): void {
        MockClient::global([ShowNodeMetricsRequest::class => MockResponse::make([
            'error' => ['code' => $code, 'message' => 'Metrics read refused.'],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ], $status)]);
        expect(Artisan::call('node:metrics', ['node' => '1', '--json' => true]))->toBe(1);
        expect(Artisan::output())->toContain($code)->not->toContain('uptime_seconds');
    })->with([['node.metrics_unavailable', 422], ['http.404', 404], ['node_access.required', 403]]);
});
