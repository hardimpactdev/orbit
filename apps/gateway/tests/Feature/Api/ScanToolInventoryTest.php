<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolStatus;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\AptRepository;
use App\Models\Node;
use App\Models\NodeFact;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Illuminate\Support\Carbon;
use Tests\Support\ToolManagerFakeSshExecutor;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-26 12:00:00', 'UTC'));
    $this->gateway = scan_node('scan-gateway', '10.44.0.2');
    $this->markAsGateway($this->gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->withHeader('X-Orbit-Request-Id', fixture_request_id());
    app()->instance(SshExecutor::class, new ToolManagerFakeSshExecutor([]));

});

describe('tool inventory authorization and input', function (): void {
    it('denies a peer without access and does not scan', function (): void {
        $node = scan_node('scan-target', '10.44.0.3', 'macos', 'mini');
        $consumer = scan_node('scan-consumer', '10.44.0.9');
        $ssh = new ToolManagerFakeSshExecutor([]);
        app()->instance(SshExecutor::class, $ssh);

        $response = $this->withServerVariables(['REMOTE_ADDR' => $consumer->wireguard_ip])
            ->getJson('/api/v1/tool-inventory?node_id='.$node->id)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'node_access.required');

        expect($ssh->commands)->toBe([])->and(Tool::query()->count())->toBe(0);
        record_fixture($response, 'tools/tool-scan/access-required', 'Orbit\Sdk\Requests\Tools\ScanToolInventoryRequest', 'GET /api/v1/tool-inventory');
    });

    it('rejects an empty body field other than the strict node id', function (string $uri, ?string $body = null): void {
        $node = scan_node('scan-target', '10.44.0.3', 'macos', 'mini');
        $ssh = new ToolManagerFakeSshExecutor([]);
        app()->instance(SshExecutor::class, $ssh);
        $response = $this->call(
            'GET',
            str_replace('{id}', (string) $node->id, $uri),
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $body,
        );

        $response->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        expect($response->getContent())->not->toContain('raw-caller-sentinel')
            ->and($ssh->commands)->toBe([]);
    })->with([
        'absent' => ['/api/v1/tool-inventory'],
        'zero' => ['/api/v1/tool-inventory?node_id=0'],
        'negative' => ['/api/v1/tool-inventory?node_id=-1'],
        'leading zero' => ['/api/v1/tool-inventory?node_id=01'],
        'fraction' => ['/api/v1/tool-inventory?node_id=1.5'],
        'word' => ['/api/v1/tool-inventory?node_id=invalid'],
        'duplicate' => ['/api/v1/tool-inventory?node_id={id}&node_id={id}'],
        'extra query' => ['/api/v1/tool-inventory?node_id={id}&token=raw-caller-sentinel'],
        'array query' => ['/api/v1/tool-inventory?node_id[]=1'],
        'body' => ['/api/v1/tool-inventory?node_id={id}', '{"token":"raw-caller-sentinel"}'],
    ]);

    it('returns 404 for a missing node without scanning', function (): void {
        $ssh = new ToolManagerFakeSshExecutor([]);
        app()->instance(SshExecutor::class, $ssh);

        $this->getJson('/api/v1/tool-inventory?node_id=999999')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'http.404');

        expect($ssh->commands)->toBe([]);
    });

    it('rejects an inactive or unmanaged node before any command', function (string $field, mixed $value): void {
        $node = scan_node('scan-target', '10.44.0.3', 'macos', 'mini');
        $node->update([$field => $value]);
        $tool = scan_tool($node, ToolManagerName::Vp, '@openai/codex');
        $ssh = new ToolManagerFakeSshExecutor([]);
        app()->instance(SshExecutor::class, $ssh);

        $response = $this->get('/api/v1/tool-inventory?node_id='.$node->id, ['Accept' => 'application/json']);

        $response->assertStatus(409)
            ->assertJsonPath('error.code', $field === 'status' ? 'tool.node_inactive' : 'tool.node_unmanaged')
            ->assertJsonPath('error.details.step', 'scan')
            ->assertJsonPath('error.details.outcome', 'manager_failed');
        expect(array_keys($response->json('error.details')))->toBe(['step', 'outcome'])
            ->and($ssh->commands)->toBe([])
            ->and($tool->refresh()->installed_version)->toBe('0.150.0');

        $fixture = match ($field) {
            'status' => 'tools/tool-scan/node-inactive',
            'ssh_host_fingerprint' => 'tools/tool-scan/node-unmanaged',
            default => null,
        };
        if ($fixture !== null) {
            record_fixture($response, $fixture, 'Orbit\Sdk\Requests\Tools\ScanToolInventoryRequest', 'GET /api/v1/tool-inventory');
        }
    })->with([
        'inactive' => ['status', LifecycleStatus::Failed],
        'no fingerprint' => ['ssh_host_fingerprint', null],
        'no wireguard' => ['wireguard_ip', null],
    ]);
});

describe('tool inventory scans', function (): void {
    it('lists each manager the agent reported, with no packages, and reads nothing on the Node', function (): void {
        $node = scan_node('scan-mini', '10.44.0.3', 'macos', 'mini');
        scan_fact($node, 'brew', ['formulae' => [], 'casks' => []]);
        scan_fact($node, 'vp', ['packages' => []]);
        $ssh = new ToolManagerFakeSshExecutor([]);
        app()->instance(SshExecutor::class, $ssh);

        $response = $this->get('/api/v1/tool-inventory?node_id='.$node->id, ['Accept' => 'application/json'])->assertOk();

        expect($response->json('data.managers.*.manager'))->toBe(['brew', 'brew-cask', 'vp', 'apt'])
            ->and($response->json('data.managers.*.scan_state'))->toBe(['complete', 'complete', 'complete', 'unsupported'])
            ->and($response->json('data.observed_at'))->toBe('2026-04-26T11:59:00+00:00')
            ->and($response->json('data.managers.0.packages'))->toBe([])
            ->and($ssh->commands)->toBe([])
            ->and(Tool::query()->count())->toBe(0)->and(ToolManagerRecord::query()->count())->toBe(0);
        record_fixture($response, 'tools/tool-scan/empty', 'Orbit\\Sdk\\Requests\\Tools\\ScanToolInventoryRequest', 'GET /api/v1/tool-inventory');
    });

    it('joins the reported packages with recorded Tools and marks what Orbit cannot adopt', function (): void {
        $node = scan_node('scan-beast', '10.44.0.3');
        $codex = scan_tool($node, ToolManagerName::Vp, '@openai/codex');
        scan_fact($node, 'vp', ['packages' => [['name' => '@openai/codex', 'version' => '0.150.0'], ['name' => 'pnpm', 'version' => '10.0.0']]]);
        scan_fact($node, 'brew', ['formulae' => [
            ['name' => 'ripgrep', 'versions' => ['14.1.0'], 'tap' => 'homebrew/core', 'on_request' => true, 'dependencies' => ['pcre2']],
            ['name' => 'pcre2', 'versions' => ['10.44'], 'tap' => 'homebrew/core', 'on_request' => false, 'dependencies' => []],
            ['name' => 'tool', 'versions' => ['1.0.0'], 'tap' => 'example/tap', 'on_request' => true, 'dependencies' => []],
            ['name' => 'wireguard-tools', 'versions' => ['1.0.20250521'], 'tap' => 'homebrew/core', 'on_request' => true, 'dependencies' => []],
        ], 'casks' => []]);

        $response = $this->get('/api/v1/tool-inventory?node_id='.$node->id, ['Accept' => 'application/json'])->assertOk();

        $brew = collect($response->json('data.managers.0.packages'))->keyBy('package');
        expect($brew['ripgrep']['adoption'])->toBe('supported')->and($brew['ripgrep']['installed_version'])->toBe('14.1.0')
            ->and($brew['pcre2']['adoption_block'])->toBe('dependency')->and($brew['pcre2']['dependency'])->toBeTrue()
            ->and($brew['tool']['adoption_block'])->toBe('unsupported_source')
            ->and($brew['wireguard-tools']['adoption_block'])->toBe('protected')
            ->and($response->json('data.managers.1.scan_state'))->toBe('unsupported')
            ->and($response->json('data.managers.2.packages.0.package'))->toBe('@openai/codex')
            ->and($response->json('data.managers.2.packages.0.tool_id'))->toBe($codex->id)
            ->and($response->json('data.managers.2.packages.1.adoption_block'))->toBe('protected')
            ->and($response->json('data.managers.3.scan_state'))->toBe('not_observed')
            ->and($response->getContent())->not->toContain('/home/');
        record_fixture($response, 'tools/tool-scan/discovered', 'Orbit\\Sdk\\Requests\\Tools\\ScanToolInventoryRequest', 'GET /api/v1/tool-inventory');
    });

    it('keeps a package whose version does not normalize adoptable, as adoption does', function (): void {
        $node = scan_node('scan-revision', '10.44.0.3');
        scan_fact($node, 'brew', ['formulae' => [['name' => 'node', 'versions' => ['25.8.1_1'], 'tap' => 'homebrew/core', 'on_request' => true, 'dependencies' => []]], 'casks' => []]);

        $this->get('/api/v1/tool-inventory?node_id='.$node->id, ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.managers.0.packages.0.installed_version', null)
            ->assertJsonPath('data.managers.0.packages.0.adoption', 'supported');
    });

    it('says a manager is not observed yet instead of reading the Node', function (): void {
        $node = scan_node('scan-new', '10.44.0.3');
        scan_fact($node, 'vp', ['packages' => []]);
        // A fact without a scope reports that no state path exists.
        NodeFact::query()->create(['node_id' => $node->id, 'kind' => 'packages', 'subject' => 'brew', 'digest' => str_repeat('b', 64),
            'observed_at' => now(), 'received_at' => now(), 'data' => ['scope' => null]]);
        $ssh = new ToolManagerFakeSshExecutor([]);
        app()->instance(SshExecutor::class, $ssh);

        $response = $this->get('/api/v1/tool-inventory?node_id='.$node->id, ['Accept' => 'application/json'])->assertOk();

        expect($response->json('data.managers.*.scan_state'))->toBe(['not_observed', 'unsupported', 'complete', 'not_observed'])
            ->and($ssh->commands)->toBe([]);
        record_fixture($response, 'tools/tool-scan/partial', 'Orbit\\Sdk\\Requests\\Tools\\ScanToolInventoryRequest', 'GET /api/v1/tool-inventory');
    });
});

function scan_node(
    string $name,
    string $address,
    string $platform = 'linux',
    string $user = 'orbit',
    string $architecture = 'arm64',
): Node {
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => $platform,
        'architecture' => $architecture,
        'public_ssh_host' => '192.0.2.40',
        'user' => $user,
        'wireguard_ip' => $address,
        'ssh_host_fingerprint' => 'SHA256:'.str_repeat('A', 43),
    ]);
}

function scan_tool(Node $node, ToolManagerName $manager, string $package): Tool
{
    $record = $node->toolManagers()->create([
        'name' => $manager->value,
        'status' => LifecycleStatus::Active,
    ]);

    return $node->tools()->create([
        'tool_manager_id' => $record->id,
        'package' => $package,
        'status' => ToolStatus::Installed,
        'installed_version' => '0.150.0',
    ]);
}

/** @param array<string, mixed> $data */
function scan_fact(Node $node, string $manager, array $data): void
{
    NodeFact::query()->updateOrCreate(['node_id' => $node->id, 'kind' => 'packages', 'subject' => $manager], [
        'digest' => bin2hex(random_bytes(32)), 'observed_at' => now()->subMinute(), 'received_at' => now(), 'data' => ['scope' => '/', ...$data]]);
}

it('reports apt repository packages and protected prerequisites without creating intent', function (): void {
    $node = scan_node('apt-target', '10.44.0.4');
    scan_fact($node, 'apt', ['packages' => [['name' => 'rex', 'version' => '0.0.123'], ['name' => 'openssh-server', 'version' => '10.0']]]);
    $repository = AptRepository::query()->create(['node_id' => $node->id, 'name' => 'rex', 'definition' => ['name' => 'rex', 'suites' => ['preview']]]);
    $manager = $node->toolManagers()->create(['name' => 'apt', 'status' => LifecycleStatus::Active]);
    $tool = $node->tools()->create(['tool_manager_id' => $manager->id, 'apt_repository_id' => $repository->id, 'package' => 'rex', 'status' => ToolStatus::Installed]);

    $response = $this->get('/api/v1/tool-inventory?node_id='.$node->id, ['Accept' => 'application/json'])->assertOk()
        ->assertJsonPath('data.managers.3.manager', 'apt')
        ->assertJsonPath('data.managers.3.scan_state', 'complete')
        ->assertJsonPath('data.managers.3.packages.0.adoption_block', 'protected')
        ->assertJsonPath('data.managers.3.packages.1.package', 'rex')
        ->assertJsonPath('data.managers.3.packages.1.package_kind', 'deb')
        ->assertJsonPath('data.managers.3.packages.1.repository.suites', ['preview'])
        ->assertJsonPath('data.managers.3.packages.1.tool_id', $tool->id);
    expect($node->tools()->count())->toBe(1);
    record_fixture($response, 'tools/tool-scan/linux', 'Orbit\\Sdk\\Requests\\Tools\\ScanToolInventoryRequest', 'GET /api/v1/tool-inventory');
});
