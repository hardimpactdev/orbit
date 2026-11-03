<?php

declare(strict_types=1);

use App\Data\Nodes\NodeData;
use App\Domain\NodeFacts\NodeAgentLiveness;
use App\Domain\NodeFacts\NodeAgentState;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Models\NodeFact;
use App\Models\Project;
use App\Models\ToolManagerRecord;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

describe('agent facts and liveness', function (): void {
    beforeEach(function (): void {
        $this->node = agent_view_node('facts-node', '10.44.0.51');
        $this->node->forceFill(['agent_secret_hash' => hash('sha256', 'facts-secret'), 'ssh_host_fingerprint' => 'SHA256:test'])->save();
        $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip])->withToken('facts-secret');
    });
    afterEach(fn () => Carbon::setTestNow());

    $gib = 1_073_741_824;
    $host = static fn (int $free = 40 * 1_073_741_824): array => ['kind' => 'host', 'observed_at' => '2026-11-02T18:00:00Z',
        'data' => ['os' => 'linux', 'arch' => 'x86_64', 'filesystems' => [['mount' => '/', 'size' => 100 * 1_073_741_824, 'free' => $free]]]];

    it('stores a changed fact once and writes nothing for an unchanged report', function () use ($host, $gib): void {
        $this->postJson('/api/v1/agent/facts', $host())->assertNoContent();
        $first = NodeFact::query()->sole();
        expect($first->data['filesystems'][0]['free'])->toBe(40 * $gib)->and($first->observed_at->toIso8601ZuluString())->toBe('2026-11-02T18:00:00Z');

        $writes = 0;
        $transactions = 0;
        Event::listen(TransactionBeginning::class, static function () use (&$transactions): void {
            $transactions++;
        });
        DB::listen(static function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)/i', $query->sql) === 1) {
                $writes++;
            }
        });
        $this->postJson('/api/v1/agent/facts', $host())->assertNoContent();
        expect($writes)->toBe(0)->and($transactions)->toBe(0);

        // Free space that moves by less than one step is no change: the disk fills with logs all the time.
        $this->postJson('/api/v1/agent/facts', $host(40 * $gib + 200_000_000))->assertNoContent();
        expect($writes)->toBe(0)->and($transactions)->toBe(0);

        $this->postJson('/api/v1/agent/facts', $host(39 * $gib + 5))->assertNoContent();
        expect($writes)->toBe(1)->and($transactions)->toBe(1)->and(NodeFact::query()->sole()->data['filesystems'][0]['free'])->toBe(39 * $gib);
    });

    it('refuses an unknown kind, malformed data and a body over 1 MiB', function () use ($host): void {
        $this->postJson('/api/v1/agent/facts', [...$host(), 'kind' => 'secrets'])->assertUnprocessable();
        $this->postJson('/api/v1/agent/facts', [...$host(), 'data' => [1, 2]])->assertUnprocessable();
        $this->postJson('/api/v1/agent/facts', ['kind' => 'units', 'observed_at' => '2026-11-02T18:00:00Z',
            'data' => ['docker' => 'available', 'units' => [['name' => 'a b', 'runtime' => 'systemd', 'state' => 'active']]]])->assertUnprocessable();
        $big = $host();
        $big['data']['os'] = str_repeat('x', 1_048_577);
        $this->postJson('/api/v1/agent/facts', $big)->assertStatus(413)->assertJsonPath('error.code', 'agent.fact_too_large');
        expect(NodeFact::query()->count())->toBe(0);
    });

    it('refuses an unknown peer and a wrong secret', function () use ($host): void {
        $this->withToken('wrong')->postJson('/api/v1/agent/facts', $host())->assertForbidden()->assertJsonPath('error.code', 'agent.secret_invalid');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson('/api/v1/agent/facts', $host())
            ->assertForbidden()->assertJsonPath('error.code', 'peer.identity_unknown');
    });

    it('names the role services the agent watches, and the apps root of an active app-dev Node', function (): void {
        $this->node->forceFill(['settings' => ['apps' => ['path' => '/fast/apps']]])->save();
        foreach (['operator' => 'server/web/public', 'hauzer' => 'public'] as $slug => $root) {
            Project::query()->create(['name' => $slug, 'slug' => $slug, 'type' => 'laravel-app', 'root' => $root,
                'repository_url' => "https://github.com/example/{$slug}.git", 'default_branch' => 'main']);
        }
        $response = $this->getJson('/api/v1/agent/watch')->assertOk()->assertJsonPath('data.apps_root', null);
        expect($response->getContent())->toContain('"applications":{}');

        $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
        $this->node->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);

        $this->getJson('/api/v1/agent/watch')->assertOk()
            ->assertJsonPath('data.services', ['caddy.service', 'docker.service'])->assertJsonPath('data.workspaces', [])
            ->assertJsonPath('data.apps_root', '/fast/apps')->assertJsonPath('data.applications', ['operator' => 'server/web']);
    });

    $checkout = static fn (string $path = '/fast/apps/acme/default', string $branch = 'main'): array => ['kind' => 'checkout',
        'subject' => $path, 'observed_at' => '2026-11-02T18:00:00Z', 'data' => ['owner' => 'nckrtl', 'top_level' => $path,
            'common_dir' => $path.'/.git', 'linked' => false, 'worktrees' => [$path], 'origin' => 'git@github.com:acme/app.git',
            'branch' => $branch, 'head' => str_repeat('a', 40), 'default_branch' => 'main', 'application' => $path, 'artisan' => 'regular', 'composer' => '{}']];

    it('stores one row per checkout and writes only the checkout that changed', function () use ($checkout): void {
        $this->postJson('/api/v1/agent/facts', $checkout())->assertNoContent();
        $this->postJson('/api/v1/agent/facts', $checkout('/fast/apps/acme/feature'))->assertNoContent();
        expect(NodeFact::query()->where('kind', 'checkout')->pluck('subject')->sort()->values()->all())
            ->toBe(['/fast/apps/acme/default', '/fast/apps/acme/feature']);

        $writes = 0;
        DB::listen(static function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)/i', $query->sql) === 1) {
                $writes++;
            }
        });
        $this->postJson('/api/v1/agent/facts', $checkout())->assertNoContent();
        expect($writes)->toBe(0);
        $this->postJson('/api/v1/agent/facts', $checkout(branch: 't3code/login'))->assertNoContent();
        expect($writes)->toBe(1)
            ->and(NodeFact::query()->where('subject', '/fast/apps/acme/default')->sole()->data['branch'])->toBe('t3code/login')
            ->and(NodeFact::query()->where('subject', '/fast/apps/acme/feature')->sole()->data['branch'])->toBe('main');
    });

    it('deletes the checkouts the index no longer names', function () use ($checkout): void {
        $this->postJson('/api/v1/agent/facts', $checkout())->assertNoContent();
        $this->postJson('/api/v1/agent/facts', $checkout('/fast/apps/acme/feature'))->assertNoContent();
        $index = static fn (array $paths): array => ['kind' => 'checkouts', 'observed_at' => '2026-11-02T18:00:00Z', 'data' => ['paths' => $paths]];

        $this->postJson('/api/v1/agent/facts', $index(['/fast/apps/acme/default']))->assertNoContent();

        expect(NodeFact::query()->where('kind', 'checkout')->pluck('subject')->all())->toBe(['/fast/apps/acme/default'])
            ->and(NodeFact::query()->where('kind', 'checkouts')->sole()->subject)->toBe('');
    });

    it('accepts the .env stat of a 0.8.0 agent and a checkout without one from an older agent', function () use ($checkout): void {
        $withEnv = $checkout();
        $withEnv['data']['env'] = ['kind' => 'regular', 'mode' => '0600', 'owner' => 'nckrtl'];
        $this->postJson('/api/v1/agent/facts', $withEnv)->assertNoContent();
        $this->postJson('/api/v1/agent/facts', $checkout('/fast/apps/acme/feature'))->assertNoContent();
        $bad = $withEnv;
        $bad['data']['env']['mode'] = '777';
        $this->postJson('/api/v1/agent/facts', $bad)->assertUnprocessable();

        expect(NodeFact::query()->where('subject', '/fast/apps/acme/default')->sole()->data['env']['mode'])->toBe('0600');
    });

    it('stores one packages fact per Tool Manager and names the managers in the watch list', function (): void {
        ToolManagerRecord::query()->create(['node_id' => $this->node->id, 'name' => 'apt', 'status' => 'active']);
        ToolManagerRecord::query()->create(['node_id' => $this->node->id, 'name' => 'composer', 'status' => 'active']);
        $this->getJson('/api/v1/agent/watch')->assertOk()->assertJsonPath('data.tools', [
            ['manager' => 'apt', 'paths' => ['/var/lib/dpkg/status']],
            ['manager' => 'composer', 'paths' => ['/opt/orbit/composer']],
        ]);
        $apt = ['kind' => 'packages', 'subject' => 'apt', 'observed_at' => '2026-11-03T00:00:00Z', 'data' => ['scope' => '/var/lib/dpkg/status',
            'packages' => [['name' => 'jq', 'version' => '1.7']], 'sources' => [],
            'keys' => [['path' => '/etc/apt/keyrings/docker.asc', 'sha256' => str_repeat('a', 64), 'content' => base64_encode('key')],
                ['path' => '/usr/share/keyrings/ubuntu-cloudimage-removed-keys.gpg', 'sha256' => hash('sha256', ''), 'content' => '']]]];

        $this->postJson('/api/v1/agent/facts', $apt)->assertNoContent();
        $this->postJson('/api/v1/agent/facts', [...$apt, 'subject' => 'composer', 'data' => ['scope' => '/opt/orbit/composer', 'roots' => ['laravel/installer'],
            'packages' => [['name' => 'laravel/installer', 'version' => 'v5.32.0']]]])->assertNoContent();
        $bad = $apt;
        $bad['data']['keys'][0]['sha256'] = 'nope';
        $this->postJson('/api/v1/agent/facts', $bad)->assertUnprocessable();
        $missing = $apt;
        unset($missing['subject']);
        $this->postJson('/api/v1/agent/facts', $missing)->assertUnprocessable();

        expect(NodeFact::query()->where('kind', 'packages')->orderBy('subject')->pluck('subject')->all())->toBe(['apt', 'composer'])
            ->and(NodeFact::query()->where('subject', 'apt')->sole()->data['packages'])->toBe([['name' => 'jq', 'version' => '1.7']]);
    });

    it('requires a subject for a checkout and refuses one for other kinds', function () use ($checkout, $host): void {
        $missing = $checkout();
        unset($missing['subject']);
        $this->postJson('/api/v1/agent/facts', $missing)->assertUnprocessable();
        $this->postJson('/api/v1/agent/facts', [...$checkout(), 'subject' => 'relative/path'])->assertUnprocessable();
        $this->postJson('/api/v1/agent/facts', [...$host(), 'subject' => '/'])->assertUnprocessable();
        expect(NodeFact::query()->count())->toBe(0);
    });

    it('reports a Node online, then offline since its last event, and unknown without a subscriber', function (): void {
        $liveness = app(NodeAgentLiveness::class);
        Carbon::setTestNow('2026-11-02 18:00:00');
        app(CacheAgentStateView::class)->putSubscriber(true, true, 1);
        expect($liveness->for($this->node)?->state)->toBe(NodeAgentState::NotSeen);

        seed_agent_view($this->node->id, []);
        expect($liveness->for($this->node)?->toArray())->toBe(['state' => 'online', 'since' => null]);

        Carbon::setTestNow('2026-11-02 18:05:00');
        app(CacheAgentStateView::class)->putSubscriber(true, true, 1);
        expect($liveness->for($this->node)?->toArray())->toBe(['state' => 'offline', 'since' => '2026-11-02T18:00:00Z'])
            ->and(NodeData::fromModel($this->node)->toArray()['agent'])
            ->toBe(['state' => 'offline', 'since' => '2026-11-02T18:00:00Z']);

        Carbon::setTestNow('2026-11-02 18:06:00');
        expect($liveness->for($this->node)?->state)->toBe(NodeAgentState::Unknown);
    });
});
