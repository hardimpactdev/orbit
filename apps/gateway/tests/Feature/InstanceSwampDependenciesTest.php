<?php

declare(strict_types=1);

use App\Domain\Instances\Dependencies\SwampDependencyReader;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Dependencies\WebSocketSwampDependencyReader;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Orbit\Sdk\Requests\Instances\ShowInstanceDependenciesRequest;
use Orbit\Sdk\Requests\Projects\ShowProjectDependenciesRequest;

beforeEach(function (): void {
    $this->travelTo('2026-10-28T00:01:00Z');
    $this->withHeader('Accept', 'application/json');
    $gateway = $this->markAsGateway(Node::query()->create(['name' => 'reader-gateway', 'public_ssh_host' => '192.0.2.91', 'status' => LifecycleStatus::Active, 'wireguard_ip' => '10.44.0.91']));
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $this->project = Project::query()->forceCreate(['id' => 20, 'name' => 'Dependencies', 'slug' => 'dependency-reader', 'repository_url' => 'https://github.com/fixture/dependency-app.git', 'default_branch' => 'main']);
    $this->instance = $this->project->instances()->create(['node_id' => $gateway->id, 'name' => 'default', 'status' => 'active', 'branch' => 'main', 'seed_commit' => str_repeat('a', 40), 'checkout_path' => '/srv/fixture']);
    $this->reader = new class implements SwampDependencyReader
    {
        public array $content = [];

        public array $calls = [];

        public bool $fail = false;

        public function read(string $dataName, ?int $version, int $maxBytes): string
        {
            $this->calls[] = [$dataName, $version, $maxBytes];
            if ($this->fail) {
                throw new RuntimeException('reader.fixture-secret upstream details');
            }

            return $this->content[$dataName.':'.($version ?? 'latest')] ?? throw new RuntimeException('Missing fixture');
        }
    };
    app()->instance(SwampDependencyReader::class, $this->reader);
    config(['swamp.read_token' => 'reader.fixture-secret']);
    $this->url = '/api/v1/instances/'.$this->instance->id.'/dependencies';
    $this->fixture = function (string $name, int $snapshot = 0): array {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/SwampDependencies/'.$name.'.json')), true, flags: JSON_THROW_ON_ERROR)['snapshots'][$snapshot];
        foreach ([$fixture['index'], ...$fixture['reports']] as $rpc) {
            $request = $rpc['request']['payload'];
            $this->reader->content[$request['dataName'].':'.($request['version'] ?? 'latest')] = $rpc['response']['payload']['data']['content'];
        }

        return $fixture;
    };
});

it('binds the native reader through the Instances family provider', function (): void {
    app()->forgetInstance(SwampDependencyReader::class);
    expect(app(SwampDependencyReader::class))->toBeInstanceOf(WebSocketSwampDependencyReader::class);
});

it('reads only the selected immutable report and preserves local packages and distinct resolutions', function (): void {
    ($this->fixture)('success');
    $response = $this->get($this->url)->assertOk()->assertJsonPath('data.availability', 'available')
        ->assertJsonPath('data.report.basis', 'locked')->assertJsonPath('data.report.observed_on', 'repository')
        ->assertJsonPath('data.report.packages.2.origin', 'path')->assertJsonPath('data.batch_pr.status', 'absent');
    record_fixture($response, 'dependencies/instance/default', ShowInstanceDependenciesRequest::class, 'GET /api/v1/instances/{instance}/dependencies');
    expect($this->reader->calls)->toHaveCount(2);
    expect($this->reader->calls[1][1])->toBe(1);
    $source = $response->json('data.sources.1.id');
    $this->get($this->url.'?source='.$source)->assertOk()->assertJsonPath('data.report.packages.0.name', 'example-lib')
        ->assertJsonPath('data.report.packages.1.name', 'example-lib')->assertJsonPath('data.report.packages.1.version', '0.9.0');
});

it('shows running and partial refreshes with original retained commit and time', function (): void {
    ($this->fixture)('running-partial');
    $this->get($this->url)->assertOk()->assertJsonPath('data.last_attempt.status', 'running')
        ->assertJsonPath('data.report.source_commit', str_repeat('a', 40))->assertJsonPath('data.report.observed_at', '2026-10-28T00:00:00Z')
        ->assertJsonPath('data.report.freshness', 'stale');
    $this->travelTo('2026-10-28T00:12:00Z');
    ($this->fixture)('running-partial', 1);
    $this->get($this->url)->assertOk()->assertJsonPath('data.last_attempt.status', 'partial')
        ->assertJsonPath('data.sources.0.last_attempt.error_code', 'registry_unavailable')->assertJsonPath('data.report.source_commit', str_repeat('a', 40));
});

it('shows a first failure and blocked advisories without making up empty success or targets', function (): void {
    ($this->fixture)('failed');
    $this->get($this->url)->assertOk()->assertJsonPath('data.last_attempt.status', 'failed')
        ->assertJsonPath('data.report', null)->assertJsonPath('data.sources.0.freshness', 'unknown');
    $this->travel(61)->seconds();
    ($this->fixture)('blocked');
    $this->get($this->url)->assertOk()->assertJsonPath('data.report.findings.0.state', 'blocked')
        ->assertJsonPath('data.report.findings.0.target_version', null)->assertJsonPath('data.report.findings.0.advisory_ids.0', 'GHSA-2345-6789-cfgh');
});

it('keeps the last known PR when lookup fails and expires cached PR certainty after five minutes', function (): void {
    $this->travelTo('2026-10-28T00:10:00Z');
    ($this->fixture)('pr-unknown');
    $this->get($this->url)->assertOk()->assertJsonPath('data.batch_pr.status', 'open')->assertJsonPath('data.batch_pr.value.number', 17);
    $this->travelTo('2026-10-28T00:11:01Z');
    ($this->fixture)('pr-unknown', 1);
    $this->get($this->url)->assertOk()->assertJsonPath('data.batch_pr.status', 'unknown')
        ->assertJsonPath('data.batch_pr.last_success_at', '2026-10-28T00:10:00Z')->assertJsonPath('data.batch_pr.value.number', 17);
});

it('preserves redacted stale evidence through outages and recovers', function (): void {
    ($this->fixture)('success');
    $this->get($this->url)->assertOk();
    $this->travel(61)->seconds();
    $this->reader->fail = true;
    $this->get($this->url)->assertOk()->assertJsonPath('data.availability', 'stale')->assertJsonPath('data.report.package_count', 3)
        ->assertJsonPath('data.batch_pr.status', 'unknown')->assertDontSee('fixture-secret');
    $this->reader->fail = false;
    $this->travel(61)->seconds();
    $this->get($this->url)->assertOk()->assertJsonPath('data.availability', 'available');
});

it('refuses access and retired mutations before any upstream read', function (): void {
    $this->postJson($this->url.'/scan', [])->assertNotFound();
    $this->postJson($this->url.'/update', [])->assertNotFound();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])->get($this->url)->assertForbidden();
    expect($this->reader->calls)->toBe([]);
});

it('reads Project results without claiming Instance provenance', function (): void {
    ($this->fixture)('success');
    $response = $this->get('/api/v1/projects/20/dependencies')->assertOk()->assertJsonPath('data.instance_id', null)->assertJsonPath('data.report.basis', 'locked');
    record_fixture($response, 'dependencies/project/default', ShowProjectDependenciesRequest::class, 'GET /api/v1/projects/{project}/dependencies');
});

it('rejects mismatched or oversized evidence without a successful empty inventory', function (string $invalid): void {
    ($this->fixture)('success');
    $key = array_key_first($this->reader->content);
    $index = json_decode($this->reader->content[$key], true, flags: JSON_THROW_ON_ERROR);
    if ($invalid === 'project') {
        $index['project_id'] = 21;
    }
    if ($invalid === 'repository') {
        $index['repository'] = 'github.com/other/repository';
    }
    if ($invalid === 'ref') {
        $index['source_ref'] = 'refs/heads/other';
    }
    if ($invalid === 'schema') {
        $index['schema_version'] = 2;
    }
    if ($invalid === 'sources') {
        $index['sources'] = array_fill(0, 65, $index['sources'][0]);
    }
    if ($invalid === 'path') {
        $index['sources'][0]['path'] = '../secret';
    }
    $json = json_encode($index, JSON_THROW_ON_ERROR);
    if ($invalid === 'duplicate') {
        $json = str_replace('"schema_version":1', '"schema_version":2,"schema_\\u0076ersion":1', $json);
    }
    if ($invalid === 'bytes') {
        $json = str_pad($json, 65_537);
    }
    $this->reader->content[$key] = $json;
    $this->get($this->url)->assertOk()->assertJsonPath('data.availability', 'unavailable')->assertJsonPath('data.report', null);
})->with(['project', 'repository', 'ref', 'schema', 'sources', 'path', 'duplicate', 'bytes']);

it('rejects malformed complete reports and keeps last-good evidence when a new report fails', function (): void {
    ($this->fixture)('success');
    $this->get($this->url)->assertOk();
    $this->travel(61)->seconds();
    $key = array_key_first($this->reader->content);
    $index = json_decode($this->reader->content[$key], true, flags: JSON_THROW_ON_ERROR);
    $index['sources'][0]['report']['version'] = 2;
    $report = $index['sources'][0]['report'];
    $this->reader->content[$key] = json_encode($index, JSON_THROW_ON_ERROR);
    $this->reader->content[$report['dataName'].':2'] = '{"stdout":"reader.fixture-secret"}';
    $this->get($this->url)->assertOk()->assertJsonPath('data.availability', 'stale')
        ->assertJsonPath('data.report.source_commit', str_repeat('a', 40))
        ->assertJsonPath('data.report.observed_at', '2026-10-28T00:00:00Z')->assertJsonPath('data.report.package_count', 3)->assertDontSee('fixture-secret');
});

it('enforces resolution and finding bounds before rendering pages', function (string $field, int $count): void {
    ($this->fixture)('success');
    $key = array_keys($this->reader->content)[1];
    $report = json_decode($this->reader->content[$key], true, flags: JSON_THROW_ON_ERROR);
    $report[$field] = array_fill(0, $count, $report[$field][0]);
    $this->reader->content[$key] = json_encode($report, JSON_THROW_ON_ERROR);
    $this->get($this->url)->assertOk()->assertJsonPath('data.availability', 'unavailable')->assertJsonPath('data.report', null);
})->with([['packages', 5_001], ['findings', 501]]);

it('redacts producer text before caching and returns typed paginated rows', function (): void {
    ($this->fixture)('success');
    $key = array_keys($this->reader->content)[1];
    $report = json_decode($this->reader->content[$key], true, flags: JSON_THROW_ON_ERROR);
    $report['packages'] = array_map(static fn (int $n): array => ['id' => 'pkg-'.$n, 'name' => 'pkg-'.$n, 'version' => 'reader.fixture-secret', 'direct' => true, 'scopes' => ['regular'], 'origin' => 'registry'], range(1, 101));
    $report['findings'][0]['package'] = 'pkg-1';
    $report['findings'][0]['current_versions'] = ['reader.fixture-secret'];
    $report['findings'][0]['reason']['text'] = 'Authorization: Bearer producer-fixture-secret';
    $this->reader->content[$key] = json_encode($report, JSON_THROW_ON_ERROR);
    $this->get($this->url)->assertOk()->assertJsonCount(100, 'data.report.packages')->assertJsonPath('data.report.package_count', 101)->assertDontSee('fixture-secret');
    $this->get($this->url.'?packages_page=2')->assertOk()->assertJsonCount(1, 'data.report.packages')->assertJsonPath('data.report.packages.0.name', 'pkg-101')->assertDontSee('fixture-secret');
    expect($this->reader->calls)->toHaveCount(2);
});

it('expires PR certainty within a cached read and preserves inventory observation age', function (): void {
    $this->travelTo('2026-10-28T00:04:59Z');
    ($this->fixture)('success');
    $this->get($this->url)->assertOk()->assertJsonPath('data.batch_pr.status', 'absent');
    $this->travel(1)->seconds();
    $this->get($this->url)->assertOk()->assertJsonPath('data.batch_pr.status', 'unknown');
    expect($this->reader->calls)->toHaveCount(2);
    $this->travelTo('2026-10-29T02:00:00Z');
    $this->get($this->url)->assertOk()->assertJsonPath('data.report.freshness', 'stale')->assertJsonPath('data.report.observed_at', '2026-10-28T00:00:00Z');
});

it('refuses invalid selectors, pagination, and an active peer without access before reads', function (): void {
    $this->get($this->url.'?source=not-a-resource')->assertUnprocessable();
    $this->get($this->url.'?packages_page=0')->assertUnprocessable();
    $this->get($this->url.'?findings_page=6')->assertUnprocessable();
    $peer = Node::query()->create(['name' => 'other-peer', 'public_ssh_host' => '192.0.2.92', 'wireguard_ip' => '10.44.0.92', 'status' => 'active']);
    $this->withServerVariables(['REMOTE_ADDR' => $peer->wireguard_ip])->get($this->url)->assertForbidden();
    $this->get('/api/v1/projects/20/dependencies')->assertForbidden();
    expect($this->reader->calls)->toBe([]);
});

it('counts Unicode fields by code point and refuses the first over-bound version', function (int $length): void {
    ($this->fixture)('success');
    $key = array_keys($this->reader->content)[1];
    $report = json_decode($this->reader->content[$key], true, flags: JSON_THROW_ON_ERROR);
    $report['packages'][0]['name'] = str_repeat('界', 512);
    $report['packages'][0]['version'] = str_repeat('界', $length);
    $report['findings'][0]['package'] = $report['packages'][0]['name'];
    $report['findings'][0]['current_versions'] = [$report['packages'][0]['version']];
    $this->reader->content[$key] = json_encode($report, JSON_THROW_ON_ERROR);
    $response = $this->get($this->url)->assertOk();
    if ($length === 256) {
        $response->assertJsonPath('data.report.packages.0.version', str_repeat('界', 256));
    } else {
        $response->assertJsonPath('data.availability', 'unavailable')->assertJsonPath('data.report', null);
    }
})->with([256, 257]);

it('rejects local paths and repository URLs before caching producer evidence', function (string $text): void {
    ($this->fixture)('success');
    $key = array_keys($this->reader->content)[1];
    $report = json_decode($this->reader->content[$key], true, flags: JSON_THROW_ON_ERROR);
    $report['findings'][0]['reason']['text'] = $text;
    $this->reader->content[$key] = json_encode($report, JSON_THROW_ON_ERROR);
    $this->get($this->url)->assertOk()->assertJsonPath('data.availability', 'unavailable')->assertJsonPath('data.report', null)->assertDontSee($text);
    $this->travel(61)->seconds();
    ($this->fixture)('success');
    $this->get($this->url)->assertOk()->assertJsonPath('data.availability', 'available');
})->with([
    'Blocked at /opt/private/vendor',
    'Blocked at:/private/vendor',
    'origin file:///home/operator/private.git',
    'origin FILE://localhost/private.git',
    'Blocked at C:\\private\\vendor',
    'Blocked at \\\\server\\private',
]);

it('round-trips indexes and reports through file cache with classes disabled and retains evidence through failures', function (): void {
    $directory = sys_get_temp_dir().'/orbit-dependency-cache-'.bin2hex(random_bytes(8));
    config(['cache.default' => 'file', 'cache.stores.file.path' => $directory, 'cache.serializable_classes' => false]);
    Cache::purge('file');
    Log::spy();
    try {
        ($this->fixture)('success');
        $this->get($this->url)->assertOk()->assertJsonPath('data.availability', 'available');
        Cache::purge('file');
        $this->get($this->url)->assertOk()->assertJsonPath('data.availability', 'available')->assertJsonPath('data.report.package_count', 3);
        expect($this->reader->calls)->toHaveCount(2);
        $this->travel(61)->seconds();
        $this->get($this->url)->assertOk()->assertJsonPath('data.availability', 'available');
        expect($this->reader->calls)->toHaveCount(3);
        $this->travel(61)->seconds();
        $key = array_key_first($this->reader->content);
        $this->reader->content[$key] = '{"invalid":"reader.fixture-secret"}';
        $this->get($this->url)->assertOk()->assertJsonPath('data.availability', 'stale')->assertJsonPath('data.report.package_count', 3)->assertDontSee('fixture-secret');
        expect($this->reader->calls)->toHaveCount(4);
        Cache::purge('file');
        $this->get($this->url)->assertOk()->assertJsonPath('data.availability', 'stale');
        expect($this->reader->calls)->toHaveCount(4);
        Log::shouldHaveReceived('warning')->with('Swamp dependency read failed.', ['project_id' => 20, 'stage' => 'index', 'code' => 'invalid'])->once();
        $this->travel(30)->seconds();
        $this->reader->fail = true;
        $this->get($this->url)->assertOk()->assertJsonPath('data.availability', 'stale')->assertJsonPath('data.report.source_commit', str_repeat('a', 40));
        expect($this->reader->calls)->toHaveCount(5);
        $this->travel(30)->seconds();
        $this->reader->fail = false;
        ($this->fixture)('success');
        $this->get($this->url)->assertOk()->assertJsonPath('data.availability', 'available');
        expect($this->reader->calls)->toHaveCount(6);
        Log::shouldHaveReceived('warning')->twice();
        Log::shouldHaveReceived('warning')->with('Swamp dependency read failed.', ['project_id' => 20, 'stage' => 'index', 'code' => 'unavailable'])->once();
    } finally {
        Cache::purge('file');
        File::deleteDirectory($directory);
    }
});

it('accepts ordinary producer prose and package file specifiers', function (string $text): void {
    ($this->fixture)('success');
    $key = array_keys($this->reader->content)[1];
    $report = json_decode($this->reader->content[$key], true, flags: JSON_THROW_ON_ERROR);
    $report['findings'][0]['reason']['text'] = $text;
    $this->reader->content[$key] = json_encode($report, JSON_THROW_ON_ERROR);
    $this->get($this->url)->assertOk()->assertJsonPath('data.availability', 'available')->assertJsonPath('data.report.findings.0.reason.text', $text);
})->with([
    'Requires PHP 8.4 / 8.5.',
    'composer.lock file: out of date',
    'Needs ext-intl (see /docs)',
    'file:../local-pkg',
    'See https://example.test/docs/install and /docs/install',
]);

it('backs off failed reports without losing last-good evidence or retrying the cached index', function (): void {
    ($this->fixture)('success');
    $this->get($this->url)->assertOk();
    $key = array_key_first($this->reader->content);
    $index = json_decode($this->reader->content[$key], true, flags: JSON_THROW_ON_ERROR);
    $index['sources'][0]['report']['version'] = 2;
    $pointer = $index['sources'][0]['report'];
    $valid = $this->reader->content[$pointer['dataName'].':1'];
    $this->reader->content[$key] = json_encode($index, JSON_THROW_ON_ERROR);
    $this->reader->content[$pointer['dataName'].':2'] = '{}';
    $this->travel(61)->seconds();
    $this->get($this->url)->assertOk()->assertJsonPath('data.availability', 'stale')->assertJsonPath('data.report.package_count', 3);
    expect($this->reader->calls)->toHaveCount(4);
    $this->get($this->url)->assertOk()->assertJsonPath('data.availability', 'stale');
    expect($this->reader->calls)->toHaveCount(4);
    $this->reader->content[$pointer['dataName'].':2'] = $valid;
    $this->travel(30)->seconds();
    $this->get($this->url)->assertOk()->assertJsonPath('data.availability', 'available');
    expect($this->reader->calls)->toHaveCount(5);
});

it('skips an uncached source report when the index refresh fails', function (): void {
    ($this->fixture)('success');
    $response = $this->get($this->url)->assertOk();
    $otherSource = $response->json('data.sources.1.id');
    $this->travel(61)->seconds();
    $this->reader->fail = true;
    $this->get($this->url.'?source='.$otherSource)->assertOk()->assertJsonPath('data.availability', 'unavailable')->assertJsonPath('data.report', null);
    expect($this->reader->calls)->toHaveCount(3);
    $this->get($this->url)->assertOk()->assertJsonPath('data.availability', 'stale')->assertJsonPath('data.report.package_count', 3);
    expect($this->reader->calls)->toHaveCount(3);
});
