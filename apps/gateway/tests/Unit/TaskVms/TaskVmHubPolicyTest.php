<?php

declare(strict_types=1);

use App\Infrastructure\Firewall\UfwManagedRule;
use App\Infrastructure\Firewall\UfwRuleShape;
use App\Infrastructure\TaskVms\TaskVmHubPolicy;

it('allows the Swamp control Node to reach the configured Pi port before dropping task traffic', function (int $port): void {
    $control = new UfwManagedRule(
        new UfwRuleShape(TaskVmHubPolicy::Comment, 'allow', 'fwd', '192.0.2.15', '10.44.0.128/25', '22', 'tcp', 'orbit', 'orbit', 'v4'),
        ['ufw'],
    );
    $input = [
        'range' => '10.44.0.128/25', 'dns' => '192.0.2.1', 'gateway' => '192.0.2.2',
        'pi_port' => $port, 'reverb' => '192.0.2.3:443', 'model' => '192.0.2.3:8317',
        'routers' => ['192.0.2.7'],
    ];
    $policy = new TaskVmHubPolicy;
    $expected = 'ip saddr 192.0.2.15 ip daddr 10.44.0.128/25 tcp dport '.$port.' accept comment "orbit:task-vm-control-pi"';
    $rendered = $policy->render($input, $control);

    expect($policy->liveRules($input, $control))->toContain($expected);
    expect($rendered)->toContain($expected);
    expect(strpos($rendered, $expected))->toBeLessThan(strpos($rendered, 'ip daddr 10.44.0.128/25 drop'));
    expect($rendered)->not->toContain('ip saddr 192.0.2.15 ip daddr 10.44.0.128/25 accept');
})->with([3774, 4888]);
