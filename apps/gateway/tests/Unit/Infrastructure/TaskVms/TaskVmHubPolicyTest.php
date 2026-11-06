<?php

declare(strict_types=1);

use App\Infrastructure\Firewall\UfwManagedRule;
use App\Infrastructure\Firewall\UfwRuleShape;
use App\Infrastructure\TaskVms\TaskVmHubPolicy;

it('lets the control Node reach the task VM range on SSH and on 443, the swamp Node on the Pi port, and each Router on the web ports', function (): void {
    $control = new UfwManagedRule(new UfwRuleShape(TaskVmHubPolicy::Comment, 'allow', 'fwd', '10.44.0.15', '10.44.0.128/25', '22', 'tcp', 'orbit', 'orbit', 'v4'), ['sudo']);

    $rules = (new TaskVmHubPolicy)->liveRules(['range' => '10.44.0.128/25', 'pi_port' => 3774, 'swamp' => '10.44.0.15', 'routers' => ['10.44.0.7']], $control);

    expect($rules)->toBe([
        'ip saddr 10.44.0.15 ip daddr 10.44.0.128/25 tcp dport 22 accept comment "orbit:task-vm-control-ssh"',
        'ip saddr 10.44.0.15 ip daddr 10.44.0.128/25 tcp dport 443 accept comment "orbit:task-vm-control-web"',
        'ip saddr 10.44.0.15 ip daddr 10.44.0.128/25 tcp dport 3774 accept comment "orbit:task-vm-swamp-pi"',
        'ip saddr 10.44.0.7 ip daddr 10.44.0.128/25 tcp dport 80 accept comment "orbit:task-vm-router-web-10-44-0-7-80"',
        'ip saddr 10.44.0.7 ip daddr 10.44.0.128/25 tcp dport 443 accept comment "orbit:task-vm-router-web-10-44-0-7-443"',
    ]);
});

it('adds no Pi rule without an active swamp Node', function (): void {
    $control = new UfwManagedRule(new UfwRuleShape(TaskVmHubPolicy::Comment, 'allow', 'fwd', '10.44.0.15', '10.44.0.128/25', '22', 'tcp', 'orbit', 'orbit', 'v4'), ['sudo']);

    $rules = (new TaskVmHubPolicy)->liveRules(['range' => '10.44.0.128/25', 'pi_port' => 3774, 'swamp' => null, 'routers' => []], $control);

    expect($rules)->not->toContain('ip saddr 10.44.0.15 ip daddr 10.44.0.128/25 tcp dport 3774 accept comment "orbit:task-vm-swamp-pi"')
        ->and((new TaskVmHubPolicy)->swampPiRule('10.44.0.15', '10.44.0.128/25', 3774)?->arguments)
        ->toBe(['sudo', 'ufw', 'route', 'allow', 'in', 'on', 'orbit', 'out', 'on', 'orbit', 'proto', 'tcp', 'from', '10.44.0.15', 'to', '10.44.0.128/25', 'port', '3774', 'comment', 'orbit:task-vm-swamp-pi']);
});
