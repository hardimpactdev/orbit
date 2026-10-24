<?php

declare(strict_types=1);

use App\Domain\Firewall\FirewallBackendStatus;
use App\Domain\Firewall\FirewallOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\Firewall\NativeUfwFirewallManager;
use App\Models\FirewallRule;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

it('sends named intent once and maps the native result', function (string $operation, string $backend): void {
    $agents = new FakeAgentCommandRunner()->answer('firewall.rule', ['backend' => $backend]);
    $rule = firewall_rule();
    $before = $rule->getAttributes();

    $result = new NativeUfwFirewallManager($agents)->{$operation}($rule);

    expect($result)->toBe(FirewallBackendStatus::from($backend));
    expect($agents->commands())->toBe(['firewall.rule'])
        ->and($agents->calls[0]['input'])->toBe([
            'node_id' => 7, 'name' => 'web', 'action' => 'allow', 'source' => 'any',
            'port' => '443', 'protocol' => 'tcp', 'operation' => $operation,
        ]);
    expect($rule->getAttributes())->toBe($before);
})->with([['converge', 'active'], ['converge', 'inactive'], ['remove', 'absent'], ['remove', 'inactive']]);

it('rejects a deny intersecting recovery SSH before calling the agent', function (): void {
    $agents = new FakeAgentCommandRunner;

    expect(fn () => new NativeUfwFirewallManager($agents)->converge(firewall_rule(['action' => 'deny', 'port' => '1:1024'])))
        ->toThrow(FirewallOperationException::class, 'public recovery SSH port');
    expect($agents->calls)->toBeEmpty();
});

it('maps native firewall refusals to the existing operation step', function (string $code, string $step): void {
    $agents = new FakeAgentCommandRunner()->answer('firewall.rule', static function () use ($code): never {
        throw new AgentCommandRefused('firewall.rule', $code, 'The firewall operation failed.', []);
    });

    try {
        new NativeUfwFirewallManager($agents)->converge(firewall_rule());
        test()->fail('Expected a firewall refusal.');
    } catch (FirewallOperationException $exception) {
        expect($exception->errorCode)->toBe($code)->and($exception->step)->toBe($step);
    }
})->with([
    ['firewall.rule_collision', 'ownership'], ['firewall.status_failed', 'status'],
    ['firewall.status_unrecognized', 'status'], ['firewall.reconcile_failed', 'reconcile'],
    ['firewall.apply_failed', 'apply'], ['firewall.verify_failed', 'verify'],
    ['firewall.remove_verify_failed', 'verify-remove'],
]);

it('refuses unsupported or unreachable targets before an agent call', function (string $platform, ?string $address): void {
    $rule = firewall_rule();
    $rule->node->platform = $platform;
    $rule->node->wireguard_ip = $address;
    $agents = new FakeAgentCommandRunner;

    expect(fn () => new NativeUfwFirewallManager($agents)->converge($rule))->toThrow(FirewallOperationException::class);
    expect($agents->calls)->toBe([]);
})->with([['macos', '10.44.0.3'], ['linux', null]]);

it('keeps named firewall management and observation behind typed agent commands', function (): void {
    foreach (['Firewall/NativeUfwFirewallManager', 'Firewall/NativeUfwFirewallInspector', 'Firewall/NativeLiveUfwReader', 'Doctor/UfwManagedRulesCheck', 'Nodes/Roles/NativeNodeRoleFirewallManager'] as $file) {
        $source = file_get_contents(app_path('Infrastructure/'.$file.'.php'));
        foreach (['SshExecutor', 'RemoteCommand', 'UfwStatusParser', "'firewall.status'", "'firewall.ufw'", '<<<'] as $forbidden) {
            expect($source)->not->toContain($forbidden);
        }
    }
});

/** @param array<string, mixed> $attributes */
function firewall_rule(array $attributes = []): FirewallRule
{
    $node = new Node([
        'name' => 'app-dev',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'public_ssh_port' => 22,
        'user' => 'nckrtl',
        'wireguard_ip' => '10.44.0.3',
    ]);
    $node->id = 7;
    $rule = new FirewallRule([
        'node_id' => 7,
        'name' => 'web',
        'action' => 'allow',
        'source' => 'any',
        'protocol' => 'tcp',
        'port' => '443',
        ...$attributes,
    ]);
    $rule->id = 11;
    $rule->setRelation('node', $node);

    return $rule;
}
