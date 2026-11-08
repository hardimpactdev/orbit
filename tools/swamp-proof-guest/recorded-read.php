<?php

declare(strict_types=1);

// Test support only. Runs inside the nested Gateway, never installed in a release.
use App\Domain\AgentView\AgentStateView;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Models\Cluster;
use App\Models\Node;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

require __DIR__.'/../../apps/gateway/vendor/autoload.php';
$app = require __DIR__.'/../../apps/gateway/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);

$result = match ($input['mode']) {
    'fixture' => fixture($input),
    'snapshot' => snapshot(),
    'request' => recordedRequest($input, $app, $kernel),
    default => throw new InvalidArgumentException('Unknown recorded-read mode.'),
};
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";

function fixture(array $input): array
{
    $kind = $input['kind'];
    if (! in_array($kind, ['retained', 'sparse', 'unmanaged', 'managed'], true)) {
        throw new InvalidArgumentException('Unknown Node fixture kind.');
    }
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'proof-'.Str::uuid(), 'status' => $kind === 'retained' ? 'failed' : 'active',
        'platform' => 'linux', 'public_ssh_host' => '192.0.2.99', 'public_ssh_port' => 2222, 'user' => 'orbit',
        'wireguard_ip' => null,
        'ssh_host_fingerprint' => $kind === 'managed' ? 'SHA256:proof-recorded' : null,
    ]));
    if ($kind === 'managed') {
        $node->updateQuietly(['wireguard_ip' => long2ip(ip2long('10.254.0.0') + $node->id)]);
    }
    if ($kind === 'retained') {
        $cluster = Cluster::query()->create(['name' => 'proof-'.Str::uuid()]);
        $node->updateQuietly([
            'cluster_id' => $cluster->id, 'architecture' => 'x86_64', 'tld' => 'proof-'.$node->id.'.orbit',
            'lan_ip' => '192.168.88.2', 'wireguard_public_key' => 'recorded-proof-key',
            'wireguard_endpoint_override' => '192.0.2.99:51820', 'dns_server_override' => '192.168.88.1',
            'settings' => ['apps' => ['path' => '/srv/proof-apps']],
            'failed_step' => 'converge:apps', 'error_code' => 'node.enrollment_failed',
        ]);
        foreach (['app-dev' => 'active', 'app-prod' => 'provisioning', 'database' => 'failed', 'metrics' => 'removing'] as $role => $status) {
            $node->roles()->create([
                'role' => $role, 'status' => $status,
                'failed_step' => $status === 'failed' ? 'converge:database' : null,
                'error_code' => $status === 'failed' ? 'node.role_failed' : null,
            ]);
        }
        $node->accessibleNodes()->attach($input['serving']);
        Node::query()->findOrFail($input['consumer'])->accessibleNodes()->attach($node);
    }

    return ['id' => $node->id, 'name' => $node->name, 'cluster_name' => $node->cluster?->name, 'tld' => $node->tld];
}

function snapshot(): array
{
    return collect(['nodes', 'node_roles', 'node_access', 'clusters'])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->all()])->all();
}

function recordedRequest(array $input, $app, Kernel $kernel): array
{
    // These are request-scoped, not changes to the running Gateway or its shared view.
    // Native network/process functions are disabled by the runner for this PHP process too.
    $contacts = [];
    Http::fake(static function () use (&$contacts): never {
        $contacts[] = 'HTTP';
        throw new RuntimeException('Outbound HTTP attempted during recorded read.');
    });
    Process::fake(static function () use (&$contacts): never {
        $contacts[] = 'process';
        throw new RuntimeException('Outbound process attempted during recorded read.');
    });
    if (isset($input['agent'])) {
        Carbon::setTestNow('2026-01-01T12:00:00Z');
        $view = new CacheAgentStateView(Cache::store('array'));
        $app->instance(AgentStateView::class, $view);
        if ($input['agent'] !== 'unknown') {
            $view->putSubscriber(true, true, 1);
        }
        if (isset($input['age'])) {
            $view->putNode((int) $input['node'], [], null, 1, CacheAgentStateView::now() - $input['age'], null);
            if ($input['age'] > CacheAgentStateView::NodeTtlSeconds) {
                $view->forgetNode((int) $input['node']);
            }
        }
    }
    $caller = Node::query()->findOrFail($input['caller']);
    $request = Request::create('/api/v1/nodes/'.$input['node'], server: [
        'REMOTE_ADDR' => $caller->wireguard_ip, 'HTTP_ACCEPT' => 'application/json',
    ]);
    $response = $kernel->handle($request);
    $result = ['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)];
    $kernel->terminate($request, $response);
    if ($contacts !== []) {
        throw new RuntimeException('Recorded read attempted outbound contact: '.implode(', ', $contacts));
    }

    return $result;
}
