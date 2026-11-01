<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentRouteDomain;
use App\Domain\Instances\InstancePhpVersionCatalog;
use App\Domain\Routes\NativeRouteInstanceFacts;
use App\Domain\Routes\NativeRouteInstanceVariantsInput;
use App\Domain\Routes\NativeRouteMutationInput;
use App\Domain\Routes\NativeRoutePublicationArtifacts;
use App\Domain\Routes\RouteRenderContext;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->manifest = tempnam(sys_get_temp_dir(), 'source-variant-agent-');
    file_put_contents($this->manifest, json_encode(['commit' => str_repeat('b', 40), 'version' => '0.5.0', 'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('a', 64), 'url' => 'https://example.test/agent']]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($this->manifest, sys_get_temp_dir()));
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('public-root');
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = static fn (array $request): array => [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => ['vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => 'local_encryption']]]];
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.address' => '192.0.2.209', 'swamp.run_token' => 'fixture-token', 'swamp.ca_path' => '/public/root.pem']);
    $cluster = Cluster::query()->create(['name' => 'variants', 'state' => 'active', 'tld' => 'variants.test']);
    $this->nodes = [];
    foreach (['workload' => 'app-dev', 'gateway' => 'gateway'] as $name => $role) {
        $n = count($this->nodes) + 41;
        $node = Node::query()->create(['name' => 'variant-'.$name, 'cluster_id' => $cluster->id, 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active', 'wireguard_ip' => '10.44.0.'.$n, 'public_ssh_host' => '192.0.2.'.$n, 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-variant-pin']);
        $node->roles()->create(['role' => $role, 'status' => 'active', 'cluster_id' => in_array($role, ['router', 'ingress'], true) ? $cluster->id : null]);
        $this->nodes[$name] = $node;
    }
    foreach (['router', 'ingress'] as $role) {
        $this->nodes['workload']->roles()->create(['role' => $role, 'status' => 'active', 'cluster_id' => $cluster->id]);
        $this->nodes[$role] = $this->nodes['workload'];
    }
    $this->nodes['gateway']->roles()->create(['role' => 'vpn', 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Variants', 'slug' => 'variants', 'type' => 'laravel-app', 'repository_url' => 'https://example.test/variants.git', 'root' => 'public']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->nodes['workload']->id, 'name' => 'prospective', 'status' => 'reserved', 'checkout_path' => '/home/orbit/projects/variants/prospective', 'selected_php_version' => null, 'source_is_laravel' => false]);
    $this->route = Route::query()->create(['project_id' => $project->id, 'cluster_id' => $cluster->id, 'domain' => 'created.variants.test', 'provenance' => 'explicit', 'publication' => 'public', 'status' => 'pending', 'sites_published' => false]);
    $this->route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
});

afterEach(function (): void {
    unlink($this->manifest);
});

it('captures every finite variant without storing source facts or publishing the reserved Route', function (): void {
    $before = $this->instance->fresh()->getAttributes();
    $variants = app(NativeRouteInstanceVariantsInput::class)->capture($this->instance, $this->route);
    $keys = [];
    foreach (app(InstancePhpVersionCatalog::class)->versions() as $version) {
        $keys[] = $version.':true';
        $keys[] = $version.':false';
    }
    expect(array_keys($variants))->toBe($keys)->and($variants)->not->toHaveKey('8.3:true')
        ->and($this->instance->fresh()->getAttributes())->toBe($before)
        ->and($this->route->fresh()->sites_published)->toBeFalse()
        ->and($this->route->fresh()->status->value)->toBe('pending')
        ->and(Operation::query()->count())->toBe(0);
    foreach ($variants as $key => $variant) {
        $publication = collect($variant['route_mutations']['forward']['publications'])->map(static fn (array $row): array => NativeRoutePublicationArtifacts::resolve($variant['route_mutations']['artifacts'], $row))->firstWhere('node_id', $this->nodes['workload']->id);
        expect($publication['before']['caddy']['content'])->not->toContain($this->route->domain)
            ->and($publication['after']['caddy']['content'])->toContain($this->route->domain, 'php_fastcgi')
            ->and((array) $publication['after']['php']['configurations'])->toHaveKey(explode(':', $key)[0])
            ->and($variant['certificates'])->not->toBeEmpty()
            ->and($variant['route_mutations']['restore']['certificates'])->toBe([]);
    }
});

it('matches the complete canonical capture with those facts stored after source resolution', function (string $version, bool $laravel): void {
    $mutations = app(NativeRouteMutationInput::class);
    $beforeSites = app(DevelopmentSiteRepository::class)->all();
    $prior = $mutations->route($this->route, environment: false);
    $prior['sites'] = [];
    $prior['intent']['certificates'] = [];
    $variant = app(NativeRouteInstanceVariantsInput::class)->capture($this->instance, $this->route)[$version.':'.($laravel ? 'true' : 'false')];
    $this->route->update(['status' => 'active', 'sites_published' => true]);
    $this->instance->update(['status' => 'active', 'provisioning_step' => 'active', 'selected_php_version' => $version, 'source_is_laravel' => $laravel]);
    $candidate = $this->route->fresh(['targets.instance.node', 'targets.instance.project', 'cluster.routerAssignment.node', 'cluster.ingressAssignment.node']);
    $candidate->forceFill(['status' => 'active', 'sites_published' => true, 'replacement_step' => RouteReplacementStep::Cleanup]);
    $desired = $mutations->route($candidate, [$candidate->id => $candidate], environment: false);
    $canonical = $mutations->capture([$prior], [$desired], [$desired], [$prior], $beforeSites, $beforeSites, $prior['intent']['node_ids'], static fn (Closure $render): array => $render());
    expect(normalize_source_variant($variant))->toBe(normalize_source_variant($canonical));
    expect($this->socket->sent)->toBe([]);
})->with(['php85-laravel' => ['8.5', true], 'php85-plain' => ['8.5', false], 'php84-laravel' => ['8.4', true], 'php84-plain' => ['8.4', false]]);

it('scopes facts to the actual Instance and restores nested and failed render scopes', function (): void {
    $context = app(RouteRenderContext::class);
    $outer = new NativeRouteInstanceFacts($this->instance->id, $this->instance->node_id, '8.5', true);
    $inner = new NativeRouteInstanceFacts($this->instance->id, $this->instance->node_id, '8.4', false);
    $other = new Instance(['selected_php_version' => '8.4', 'source_is_laravel' => false]);
    $other->id = $this->instance->id + 100;
    $context->renderInstanceFacts($outer, function () use ($context, $inner, $other): void {
        expect($context->phpVersion($this->instance))->toBe('8.5')->and($context->laravel($this->instance))->toBeTrue()
            ->and($context->phpVersion($other))->toBe('8.4')->and($context->laravel($other))->toBeFalse();
        expect(fn () => $context->renderInstanceFacts($inner, function () use ($context): void {
            expect($context->phpVersion($this->instance))->toBe('8.4')->and($context->laravel($this->instance))->toBeFalse();
            throw new RuntimeException('render failed');
        }))->toThrow(RuntimeException::class, 'render failed');
        expect($context->phpVersion($this->instance))->toBe('8.5');
        $context->renderActual(fn () => expect($context->phpVersion($this->instance))->toBeNull()->and($context->laravel($this->instance))->toBeFalse());
        expect($context->phpVersion($this->instance))->toBe('8.5');
    });
    expect($context->phpVersion($this->instance))->toBeNull()->and($context->laravel($this->instance))->toBeFalse();
    expect(fn () => new NativeRouteInstanceFacts($this->instance->id, $this->instance->node_id, '8.3', true))->toThrow(ResourceOperationException::class);
});

it('refuses an unrelated or already published Route before copying any private material', function (bool $published): void {
    if ($published) {
        $this->route->update(['status' => 'active', 'sites_published' => true]);
    } else {
        $this->route->targets()->delete();
    }
    expect(fn () => app(NativeRouteInstanceVariantsInput::class)->capture($this->instance, $this->route))->toThrow(ResourceOperationException::class)
        ->and($this->socket->sent)->toBe([]);
})->with([false, true]);

it('requires explicit same-placement facts for a reserved environment and preserves three argument callers', function (): void {
    $resolver = app(InstanceEnvironmentContextResolver::class);
    $domain = InstanceEnvironmentRouteDomain::Candidate;
    $instance = $this->instance->fresh();
    $before = $instance->getAttributes();
    expect(fn () => $resolver->resolveForBoundRoute($instance, $this->route, $domain))->toThrow(ResourceOperationException::class);
    $facts = new NativeRouteInstanceFacts($instance->id, $instance->node_id, '8.4', true);
    $prospective = $resolver->resolveForBoundRoute($instance, $this->route, $domain, $facts);
    expect($prospective->laravel)->toBeTrue()->and($prospective->path)->toBe($instance->applicationDirectory())
        ->and($prospective->nodeId)->toBe($instance->node_id)
        ->and($instance->fresh()->getAttributes())->toBe($before);
    expect(fn () => $resolver->resolveForBoundRoute($instance, $this->route, $domain,
        new NativeRouteInstanceFacts($instance->id, $instance->node_id + 100, '8.4', true)))->toThrow(ResourceOperationException::class);
    expect(fn () => $resolver->resolveForBoundRoute($instance, $this->route, $domain,
        new NativeRouteInstanceFacts($instance->id + 100, $instance->node_id, '8.4', true)))->toThrow(ResourceOperationException::class);

    $this->route->update(['status' => 'active', 'sites_published' => true]);
    $instance->update(['status' => 'active', 'provisioning_step' => 'active']);
    $stored = $resolver->resolveForBoundRoute($instance, $this->route, $domain);
    expect($stored->laravel)->toBeFalse()->and($stored->path)->toBe($instance->checkout_path);
    $instance->update(['provisioning_step' => 'source-resolved']);
    expect(fn () => $resolver->resolveForBoundRoute($instance, $this->route, $domain))->toThrow(ResourceOperationException::class);
});

function normalize_source_variant(array $capture): array
{
    $capture = json_decode(preg_replace('/route-[a-f0-9]{16}-/', 'route-CAPTURE-', json_encode($capture, JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
    $normalize = static function (array $value) use (&$normalize): array {
        if (isset($value['scope'], $value['root_hash'], $value['version'])) {
            expect($value['version'])->toMatch('/^[a-f0-9]{16}$/');
            $value['version'] = 'CERTIFICATE_VERSION';
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $normalize($item);
            }
        }

        return $value;
    };

    return $normalize($capture);
}

it('omits unrelated stored PHP sites from prospective deltas without changing their stored facts', function (): void {
    $other = Instance::query()->create(['project_id' => $this->instance->project_id, 'node_id' => $this->instance->node_id, 'name' => 'unrelated', 'status' => 'active', 'provisioning_step' => 'active', 'checkout_path' => '/home/orbit/projects/variants/unrelated', 'selected_php_version' => '8.4', 'source_is_laravel' => false]);
    $route = Route::query()->create(['project_id' => $other->project_id, 'cluster_id' => $this->route->cluster_id, 'domain' => 'unrelated.variants.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending', 'sites_published' => false]);
    $route->targets()->create(['instance_id' => $other->id, 'position' => 0]);
    $route->update(['status' => 'active', 'sites_published' => true]);

    $variants = app(NativeRouteInstanceVariantsInput::class)->capture($this->instance, $this->route);

    foreach ($variants as $variant) {
        $publication = collect($variant['route_mutations']['forward']['publications'])->map(static fn (array $row): array => NativeRoutePublicationArtifacts::resolve($variant['route_mutations']['artifacts'], $row))->firstWhere('node_id', $other->node_id);
        expect($publication['before']['caddy']['content'])->not->toContain($route->domain)
            ->and($publication['after']['caddy']['content'])->not->toContain($route->domain);
    }
    expect($other->fresh()->selected_php_version)->toBe('8.4')->and($other->fresh()->source_is_laravel)->toBeFalse();
});

it('captures finite source variants for a new Instance with an empty environment store', function (): void {
    $variants = app(NativeRouteInstanceVariantsInput::class)->capture($this->instance, $this->route);

    expect(array_keys($variants))->toBe(['8.5:true', '8.5:false', '8.4:true', '8.4:false'])
        ->and($this->instance->environmentValues()->count())->toBe(0);
});

it('captures no environment or URL material even when the Instance has stored values', function (bool $webRoot): void {
    foreach (['APP_NAME' => 'Parent-owned name', 'APP_KEY' => 'parent-owned-key', 'APP_URL' => 'https://parent-owned.test'] as $key => $value) {
        $this->instance->environmentValues()->create(['env_key' => $key, 'env_value' => $value]);
    }
    if ($webRoot) {
        $this->route->update(['web_root' => 'alternate/public']);
    }
    $before = $this->instance->environmentValues()->pluck('env_value', 'env_key')->all();

    $variants = app(NativeRouteInstanceVariantsInput::class)->capture($this->instance, $this->route);

    foreach ($variants as $variant) {
        expect($variant['route_mutations']['forward']['url_writes'])->toBe([])
            ->and($variant['route_mutations']['restore']['url_writes'])->toBe([])
            ->and($variant['execution_secrets'])->toBe([]);
    }
    expect($this->socket->sent)->toBe([])
        ->and($this->instance->environmentValues()->pluck('env_value', 'env_key')->all())->toBe($before);
})->with(['primary' => false, 'additional-web-root' => true]);
