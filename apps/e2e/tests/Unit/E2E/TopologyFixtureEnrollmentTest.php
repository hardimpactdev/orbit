<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\TopologyFixtureEnrollment;
use App\E2E\Value\TopologyRecipe;
use Tests\Support\FakeIncus;
use Tests\TestCase;

uses(TestCase::class);

function fixtureEnrollmentHost(?TopologyRecipe $recipe = null): array
{
    $incus = new FakeIncus()->install();
    $target = featureTarget('TST-11', recipe: $recipe ?? TopologyRecipe::compose(['app-dev']));
    foreach ($target->instances() as $instance) {
        $incus->instances[$instance] = [...$incus->instances[IncusHost::TEMPLATE], 'status' => 'Running', 'config' => ['user.orbit.e2e.owner' => 'orbit-e2e']];
    }

    return [$incus, $target, new TopologyFixtureEnrollment(new IncusHost)];
}

it('passes only actual public guest facts and fixture role intent to the agreed seeder', function (): void {
    [$incus, $target, $fixtures] = fixtureEnrollmentHost();
    $ssh = base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('s', 32));
    $key = base64_encode(str_repeat('p', 32));
    $incus->respond(static fn (): array => [json_encode(['wireguard_public_key' => $key, 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => $ssh]), '', 0]);
    $addresses = ['gateway' => '192.0.2.10', 'app-dev' => '192.0.2.11', 'operator' => '192.0.2.14'];
    $input = $fixtures->capture($target, $addresses, array_fill_keys($target->recipe->nodeKeys(), 'x86_64'), ['url' => 'https://repositories.orbit/seed.git', 'commit' => str_repeat('a', 40), 'branch' => 'main']);
    expect($input['nodes'][0])->toMatchArray(['key' => 'gateway', 'public_address' => '192.0.2.10', 'wireguard_public_key' => $key, 'ssh_host_key' => $ssh, 'roles' => ['gateway', 'vpn']])
        ->and($input['nodes'][1])->toMatchArray(['key' => 'app-dev', 'tld' => 'beast', 'wireguard_ip' => '10.44.0.2'])
        ->and($input['nodes'][2]['roles'])->toBe([])
        ->and($input['project']['repository_url'])->toBe('https://repositories.orbit/seed.git');
    $receipt = ['seeded' => true, 'cluster_id' => 7, 'project_id' => 8, 'nodes' => ['gateway' => 11, 'app-dev' => 12, 'operator' => 13]];
    $incus->respond(static fn (): array => [json_encode($receipt), '', 0]);
    expect($fixtures->seed($target, $input))->toBe($receipt)
        ->and($incus->execs[3]['argv'])->toBe(['/usr/local/bin/converge-gateway.sh', 'seed'])
        ->and(json_decode($incus->execs[3]['stdin'], true))->toBe($input);
});

it('refuses missing or failed public identities without invoking the seeder', function (string $output, int $exit): void {
    [$incus, $target, $fixtures] = fixtureEnrollmentHost();
    $incus->respond(static fn (): array => [$output, 'unexported diagnostics', $exit]);
    expect(fn () => $fixtures->capture($target, array_fill_keys($target->recipe->nodeKeys(), '192.0.2.10'), array_fill_keys($target->recipe->nodeKeys(), 'x86_64'), ['url' => 'https://repositories.orbit/seed.git', 'commit' => str_repeat('a', 40), 'branch' => 'main']))
        ->toThrow(RuntimeException::class, 'e2e.fixture_public_identity_invalid');
    expect(array_column($incus->execs, 'argv'))->each->toBe(['/usr/local/bin/prepare-node.sh', 'fixture-facts']);
})->with([['{}', 0], ['invalid', 0], ['{}', 1]]);

it('leaves declared extra roles for normal product convergence instead of seeding active role rows', function (): void {
    [$incus, $target, $fixtures] = fixtureEnrollmentHost(TopologyRecipe::compose(['app-dev+router+vpn']));
    $ssh = base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('s', 32));
    $incus->respond(static fn (): array => [json_encode(['wireguard_public_key' => base64_encode(str_repeat('p', 32)), 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => $ssh]), '', 0]);
    $input = $fixtures->capture($target, array_fill_keys($target->recipe->nodeKeys(), '192.0.2.10'), array_fill_keys($target->recipe->nodeKeys(), 'x86_64'), ['url' => 'https://repositories.orbit/seed.git', 'commit' => str_repeat('a', 40), 'branch' => 'main']);

    expect($target->recipe->node('app-dev')->roles)->toBe(['app-dev', 'router', 'vpn'])
        ->and($input['nodes'][1]['roles'])->toBe(['app-dev']);
});

it('refuses seed success without every assigned topology Node id', function (array $nodes): void {
    [$incus, $target, $fixtures] = fixtureEnrollmentHost();
    $incus->respond(static fn (): array => [json_encode(['seeded' => true, 'cluster_id' => 1, 'project_id' => 2, 'nodes' => $nodes]), '', 0]);
    expect(fn () => $fixtures->seed($target, []))->toThrow(RuntimeException::class, 'e2e.fixture_seed_failed');
})->with([[['gateway' => 1]], [['gateway' => 1, 'app-dev' => 2, 'operator' => 0]], [['gateway' => 1, 'app-dev' => 1, 'operator' => 3]]]);
