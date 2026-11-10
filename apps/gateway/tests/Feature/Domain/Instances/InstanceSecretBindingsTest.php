<?php

declare(strict_types=1);

use App\Domain\Instances\Environment\DevelopmentCreateEnvironment;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceSecretBindings;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use App\Models\InstanceRuntime;
use App\Models\InstanceSecretBinding;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

function secretsNode(string $name, string $ip, ?string $bridge = null): Node
{
    return Node::withoutEvents(function () use ($name, $ip, $bridge): Node {
        $node = Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => $ip, 'public_ssh_host' => $ip, 'user' => 'orbit']);
        $node->forceFill(['instance_bridge' => $bridge])->save();

        return $node;
    });
}

function secretsInstance(Node $node, string $name = 'default', bool $container = true): Instance
{
    $project = Project::query()->firstOrCreate(['slug' => 'shop'], ['name' => 'Shop', 'repository_url' => 'https://github.com/nckrtl/shop.git', 'default_branch' => 'main', 'root' => 'public']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => $name, 'checkout_path' => "/srv/shop/{$name}"]);
    if ($container) {
        InstanceRuntime::query()->create(['instance_id' => $instance->id, 'node_id' => $node->id, 'incus_project' => 'orbit-instances',
            'container_name' => 'instance-'.$instance->id, 'bridge_address' => $name === 'default' ? '10.253.0.2' : '10.253.0.3', 't3_port' => $name === 'default' ? null : 3800,
            'runtime_version' => '2026.11.09', 'agent_version' => '2026.11.09', 'power_state' => InstanceRuntimePowerState::Running]);
    }
    foreach (['STRIPE_SECRET' => 'sk_live_REAL', 'POSTMARK_TOKEN' => 'pm-REAL', 'APP_KEY' => 'base64:key', 'APP_NAME' => 'Shop'] as $key => $value) {
        InstanceEnvironmentValue::query()->create(['instance_id' => $instance->id, 'env_key' => $key, 'env_value' => $value]);
    }

    return $instance;
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->caller = secretsNode('caller', '10.44.0.9');
    $this->node = secretsNode('beast', '10.44.0.7', '10.253.0.0/24');
    $this->caller->accessibleNodes()->attach($this->node);
    $this->instance = secretsInstance($this->node);
    $this->api = fn () => test()->withServerVariables(['REMOTE_ADDR' => '10.44.0.9']);
});

describe('instance:secret:bind', function (): void {
    it('marks a stored key and answers with its placeholder, never the value', function (): void {
        $response = ($this->api)()->putJson("/api/v1/instances/{$this->instance->id}/secret-bindings/STRIPE_SECRET",
            ['hosts' => ['API.stripe.com.'], 'prefix' => 'sk_test_'])->assertOk();

        $data = $response->json('data');
        expect($data['key'])->toBe('STRIPE_SECRET')
            ->and($data['hosts'])->toBe(['api.stripe.com'])
            ->and($data['headers'])->toBe(['Authorization'])
            ->and($data['placeholder'])->toMatch('/\Ask_test_orbit_secret_[a-z0-9]{32}\z/')
            ->and($data['changed'])->toBeTrue()
            ->and($response->getContent())->not->toContain('sk_live_REAL');
        expect(InstanceSecretBinding::query()->count())->toBe(1);
        // The stored value is untouched: it stays the only value.
        expect(app(InstanceEnvironmentStore::class)->values($this->instance)['STRIPE_SECRET'])->toBe('sk_live_REAL');
    });

    it('keeps the placeholder when the hosts or headers change, and reports no change for the same mark', function (): void {
        $first = ($this->api)()->putJson("/api/v1/instances/{$this->instance->id}/secret-bindings/POSTMARK_TOKEN",
            ['hosts' => ['api.postmarkapp.com'], 'headers' => ['x-postmark-server-token']])->assertOk()->json('data');
        $same = ($this->api)()->putJson("/api/v1/instances/{$this->instance->id}/secret-bindings/POSTMARK_TOKEN",
            ['hosts' => ['api.postmarkapp.com'], 'headers' => ['X-Postmark-Server-Token']])->assertOk()->json('data');
        $wider = ($this->api)()->putJson("/api/v1/instances/{$this->instance->id}/secret-bindings/POSTMARK_TOKEN",
            ['hosts' => ['api.postmarkapp.com', '*.postmarkapp.com']])->assertOk()->json('data');

        expect($first['headers'])->toBe(['X-Postmark-Server-Token'])
            ->and($same['changed'])->toBeFalse()
            ->and($wider['changed'])->toBeTrue()
            ->and($wider['hosts'])->toBe(['*.postmarkapp.com', 'api.postmarkapp.com'])
            ->and($wider['headers'])->toBe(['Authorization'])
            ->and($wider['placeholder'])->toBe($first['placeholder']);
    });

    it('refuses a key without a stored value, APP_KEY and a checkout Instance', function (): void {
        ($this->api)()->putJson("/api/v1/instances/{$this->instance->id}/secret-bindings/MISSING_KEY", ['hosts' => ['api.example.com']])
            ->assertStatus(422)->assertJsonPath('error.code', 'instance.secret_key_missing');
        ($this->api)()->putJson("/api/v1/instances/{$this->instance->id}/secret-bindings/APP_KEY", ['hosts' => ['api.example.com']])
            ->assertStatus(422)->assertJsonPath('error.code', 'instance.secret_key_refused');
        $checkout = secretsInstance($this->node, 'feature', container: false);
        ($this->api)()->putJson("/api/v1/instances/{$checkout->id}/secret-bindings/STRIPE_SECRET", ['hosts' => ['api.stripe.com']])
            ->assertStatus(409)->assertJsonPath('error.code', 'instance.secret_container_only');
        expect(InstanceSecretBinding::query()->count())->toBe(0);
    });

    it('refuses hosts and headers the proxy cannot bind', function (array $body): void {
        ($this->api)()->putJson("/api/v1/instances/{$this->instance->id}/secret-bindings/STRIPE_SECRET", $body)
            ->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');
        expect(InstanceSecretBinding::query()->count())->toBe(0);
    })->with([
        'no host' => [['hosts' => []]],
        'an address' => [['hosts' => ['1.2.3.4']]],
        'an empty label' => [['hosts' => ['api..stripe.com']]],
        'a port' => [['hosts' => ['api.stripe.com:443']]],
        'a bare wildcard' => [['hosts' => ['*']]],
        'a numeric last label' => [['hosts' => ['example.123']]],
        'nine hosts' => [['hosts' => array_map(static fn (int $i): string => "h{$i}.example.com", range(1, 9))]],
        'the Host header' => [['hosts' => ['api.stripe.com'], 'headers' => ['Host']]],
        'a proxy header' => [['hosts' => ['api.stripe.com'], 'headers' => ['Proxy-Authorization']]],
        'a bad prefix' => [['hosts' => ['api.stripe.com'], 'prefix' => 'sk test']],
        'a value field' => [['hosts' => ['api.stripe.com'], 'value' => 'sk_live_REAL']],
    ]);

    it('needs access to the Instance\'s Node', function (): void {
        $this->caller->accessibleNodes()->detach($this->node);

        ($this->api)()->putJson("/api/v1/instances/{$this->instance->id}/secret-bindings/STRIPE_SECRET", ['hosts' => ['api.stripe.com']])
            ->assertStatus(403)->assertJsonPath('error.code', 'node_access.required');
    });
});

describe('instance:secret:list and unbind', function (): void {
    it('lists bindings by key and removes one', function (): void {
        $bindings = app(InstanceSecretBindings::class);
        $bindings->bind($this->instance, 'STRIPE_SECRET', ['api.stripe.com'], [], null);
        $bindings->bind($this->instance, 'POSTMARK_TOKEN', ['api.postmarkapp.com'], ['X-Postmark-Server-Token'], null);

        expect(($this->api)()->getJson("/api/v1/instances/{$this->instance->id}/secret-bindings")->assertOk()->json('data.*.key'))
            ->toBe(['POSTMARK_TOKEN', 'STRIPE_SECRET']);
        ($this->api)()->deleteJson("/api/v1/instances/{$this->instance->id}/secret-bindings/STRIPE_SECRET")->assertOk()->assertJsonPath('data.key', 'STRIPE_SECRET');
        ($this->api)()->deleteJson("/api/v1/instances/{$this->instance->id}/secret-bindings/STRIPE_SECRET")
            ->assertStatus(404)->assertJsonPath('error.code', 'instance.secret_binding_missing');
        expect(InstanceSecretBinding::query()->pluck('env_key')->all())->toBe(['POSTMARK_TOKEN']);
    });
});

describe('delivery', function (): void {
    it('puts the placeholder in the container .env and the value only in the Node file', function (): void {
        $bindings = app(InstanceSecretBindings::class);
        $stripe = $bindings->bind($this->instance, 'STRIPE_SECRET', ['api.stripe.com'], [], 'sk_test_')['binding'];
        $values = app(InstanceEnvironmentStore::class)->values($this->instance);

        $env = app(DevelopmentCreateEnvironment::class)->content($this->instance);
        expect($env)->toContain('STRIPE_SECRET="'.$stripe->placeholder.'"')
            ->and($env)->toContain('POSTMARK_TOKEN="pm-REAL"')
            ->and($env)->not->toContain('sk_live_REAL');

        $file = json_decode($bindings->nodeFile($this->instance, $this->instance->runtime, $values), true, flags: JSON_THROW_ON_ERROR);
        expect($file)->toBe(['name' => 'instance-'.$this->instance->id, 'address' => '10.253.0.2', 'bindings' => [
            ['key' => 'STRIPE_SECRET', 'placeholder' => $stripe->placeholder, 'hosts' => ['api.stripe.com'], 'headers' => ['Authorization'], 'value' => 'sk_live_REAL'],
        ]]);
    });

    it('leaves out a binding whose key lost its value, and holds no secret in its own row', function (): void {
        $bindings = app(InstanceSecretBindings::class);
        $bindings->bind($this->instance, 'STRIPE_SECRET', ['api.stripe.com'], [], null);
        InstanceEnvironmentValue::query()->where('env_key', 'STRIPE_SECRET')->delete();

        $file = json_decode($bindings->nodeFile($this->instance, $this->instance->runtime, app(InstanceEnvironmentStore::class)->values($this->instance)), true, flags: JSON_THROW_ON_ERROR);
        expect($file['bindings'])->toBe([])
            ->and(json_encode(DB::table('instance_secret_bindings')->get()))->not->toContain('sk_live_REAL');
    });

    it('gives a clone the default\'s bindings with the same placeholders', function (): void {
        $bindings = app(InstanceSecretBindings::class);
        $stripe = $bindings->bind($this->instance, 'STRIPE_SECRET', ['api.stripe.com'], [], null)['binding'];
        $clone = secretsInstance($this->node, 'feature');

        $bindings->copyForClone($this->instance, $clone);

        expect(array_map(InstanceSecretBindings::present(...), $bindings->list($clone)))->toBe([
            ['instance_id' => $clone->id, 'key' => 'STRIPE_SECRET', 'hosts' => ['api.stripe.com'], 'headers' => ['Authorization'], 'placeholder' => $stripe->placeholder],
        ]);
    });
});
