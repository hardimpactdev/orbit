<?php

declare(strict_types=1);

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Processes\ProcessTarget;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;

describe('ProcessUser', function (): void {
    it('refuses stored user directive injection and root', function (string $user): void {
        $process = new Process([
            'name' => 'worker', 'working_directory' => '/home/worker', 'restart_policy' => 'never',
            'runtime_config' => ['command' => ['/usr/bin/sleep', '60'], 'user' => $user],
        ]);
        $process->id = 9;
        $target = new ProcessTarget(node: new Node(['name' => 'beast']), user: 'orbit', checkoutPath: '/home/orbit');
        expect(fn (): string => new SystemdProcessRenderer()->render($process, $target))->toThrow(InvalidArgumentException::class);
    })->with(['root', "worker\nGroup=root", '--root']);
});

it('renders an Orbit-owned systemd unit with fixed argv and the target identity', function (): void {
    $node = new Node(['name' => 'production', 'wireguard_ip' => '10.44.0.4']);
    $process = new Process([
        'name' => 'queue',
        'runtime_config' => [
            'command' => ['/usr/bin/php', 'artisan', 'queue:work', '--queue=high priority', '$LITERAL', '%n'],
            'environment_file' => '/var/www/docs/main/.env',
        ],
        'working_directory' => '/var/www/docs/main',
        'restart_policy' => 'on-failure',
    ]);
    $process->id = 17;
    $target = new ProcessTarget(
        node: $node,
        user: 'orbit-docs',
        checkoutPath: '/var/www/docs/main',
    );

    $renderer = new SystemdProcessRenderer;
    $unit = $renderer->render($process, $target);

    expect($renderer->unitName($process))
        ->toBe('orbit-process-17-queue.service')
        ->and($unit)
        ->toContain('X-Orbit-Process-ID=17')
        ->toContain('User=orbit-docs')
        ->toContain('WorkingDirectory=/var/www/docs/main')
        ->toContain('EnvironmentFile=-/var/www/docs/main/.env')
        ->toContain(
            'Environment=PATH=/usr/local/bin:/opt/orbit/composer/vendor/bin:/usr/bin:/bin',
        )
        ->toContain('Environment=NODE_USE_SYSTEM_CA=1')
        ->toContain('ExecStart="/usr/bin/php" "artisan" "queue:work" "--queue=high priority" "$$LITERAL" "%%n"')
        ->toContain('Restart=on-failure')
        ->not->toContain('VITE_DEV_SERVER_CERT')
        ->not->toContain('VITE_DEV_SERVER_KEY')
        ->not->toContain('ORBIT_DEV_SERVER_ORIGIN')
        ->not->toContain('/bin/bash')
        ->not->toContain('sh -c');

    expect(strpos(haystack: $unit, needle: 'Environment=PATH='))
        ->toBeLessThan(strpos(haystack: $unit, needle: 'EnvironmentFile='));
    expect(strpos(haystack: $unit, needle: 'Environment=NODE_USE_SYSTEM_CA=1'))
        ->toBeLessThan(strpos(haystack: $unit, needle: 'EnvironmentFile='));
});

it('omits EnvironmentFile for a Node Process without an environment file', function (): void {
    $process = new Process([
        'name' => 'log-shipper',
        'runtime_config' => [
            'command' => ['/usr/local/bin/log-shipper'],
            'environment_file' => '',
        ],
        'working_directory' => '/home/orbit',
        'restart_policy' => 'never',
    ]);
    $process->id = 9;
    $target = new ProcessTarget(
        node: new Node(['name' => 'beast']),
        user: 'orbit',
        checkoutPath: '/home/orbit',
        environmentFile: '',
    );

    $renderer = new SystemdProcessRenderer;
    $unit = $renderer->render($process, $target);

    expect($renderer->unitName($process))
        ->toBe('orbit-process-9-log-shipper.service')
        ->and($unit)
        ->toContain('X-Orbit-Process-ID=9')
        ->toContain('User=orbit')
        ->toContain('WorkingDirectory=/home/orbit')
        ->not->toContain('EnvironmentFile=');
});

it('maps common restart policies to valid systemd values', function (string $policy, string $expected): void {
    $process = new Process([
        'name' => 'worker',
        'runtime_config' => [
            'command' => ['/usr/bin/sleep', '60'],
            'environment_file' => '/home/orbit/apps/docs/.env',
        ],
        'working_directory' => '/home/orbit/apps/docs',
        'restart_policy' => $policy,
    ]);
    $process->id = 2;
    $target = new ProcessTarget(
        node: new Node(['name' => 'dev']),
        user: 'orbit',
        checkoutPath: '/home/orbit/apps/docs',
    );

    expect(new SystemdProcessRenderer()->render($process, $target))->toContain("Restart={$expected}");
})->with([
    'never' => ['never', 'no'],
    'on failure' => ['on-failure', 'on-failure'],
    'always' => ['always', 'always'],
    'unless stopped' => ['unless-stopped', 'always'],
]);

it('pins derived instance and workspace Vite TLS paths after the app environment file', function (string $scope): void {
    $process = new Process([
        'name' => 'vite',
        'runtime_config' => [
            'command' => ['/usr/bin/npm', 'run', 'dev'],
            'environment_file' => '/tmp/.env',
            'environment' => [
                'VITE_DEV_SERVER_CERT' => '/bad/cert.pem',
                'VITE_DEV_SERVER_KEY' => '/bad/key.pem',
            ],
        ],
        'working_directory' => '/tmp',
        'restart_policy' => 'never',
    ]);
    $process->id = 8;
    $target = new ProcessTarget(
        node: new Node(['name' => 'dev']),
        user: 'orbit',
        checkoutPath: '/tmp',
        certificateScope: $scope,
    );

    $unit = new SystemdProcessRenderer()->render(
        $process,
        $target,
        new ManagedUserAccount('orbit', 'orbit', '/srv/orbit home'),
    );

    expect($unit)
        ->toContain(
            "Environment=VITE_DEV_SERVER_CERT=/srv/orbit\\x20home/.orbit/certificates/{$scope}/current/cert.pem",
        )
        ->toContain("Environment=VITE_DEV_SERVER_KEY=/srv/orbit\\x20home/.orbit/certificates/{$scope}/current/key.pem")
        ->toContain(
            "ExecStart=\"/usr/bin/env\" \"VITE_DEV_SERVER_CERT=/srv/orbit home/.orbit/certificates/{$scope}/current/cert.pem\" \"VITE_DEV_SERVER_KEY=/srv/orbit home/.orbit/certificates/{$scope}/current/key.pem\" \"/usr/bin/npm\" \"run\" \"dev\"",
        )
        ->not->toContain('/bad/cert.pem')
        ->not->toContain('/bad/key.pem')
        ->not->toContain('ORBIT_DEV_SERVER_ORIGIN');
})->with([
    'instance' => ['instance-3'],
    'workspace' => ['workspace-4'],
]);

it('rejects missing or mismatched accounts for certificate scopes', function (?ManagedUserAccount $account): void {
    $process = new Process([
        'name' => 'vite',
        'runtime_config' => [
            'command' => ['/usr/bin/npm'],
            'environment_file' => '/tmp/.env',
        ],
        'working_directory' => '/tmp',
        'restart_policy' => 'never',
    ]);
    $process->id = 9;
    $target = new ProcessTarget(
        node: new Node(['name' => 'dev']),
        user: 'orbit',
        checkoutPath: '/tmp',
        certificateScope: 'instance-9',
    );

    expect(fn () => new SystemdProcessRenderer()->render($process, $target, $account))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'missing account' => [null],
    'mismatched account' => [new ManagedUserAccount('other', 'orbit', '/srv/orbit')],
]);

it('systemd-escapes accepted working and environment file paths with spaces', function (): void {
    $process = new Process([
        'name' => 'worker',
        'runtime_config' => [
            'command' => ['/usr/bin/php', 'artisan'],
            'environment_file' => '/home/orbit/Work Trees/docs/.env',
        ],
        'working_directory' => '/home/orbit/Work Trees/docs',
        'restart_policy' => 'never',
    ]);
    $process->id = 3;
    $target = new ProcessTarget(
        node: new Node(['name' => 'dev']),
        user: 'orbit',
        checkoutPath: '/home/orbit/Work Trees/docs',
    );

    expect(new SystemdProcessRenderer()->render($process, $target))
        ->toContain('WorkingDirectory=/home/orbit/Work\\x20Trees/docs')
        ->toContain('EnvironmentFile=-/home/orbit/Work\\x20Trees/docs/.env');
});

it('projects a persisted systemd environment map after the environment file', function (): void {
    $process = new Process([
        'name' => 'ai-router',
        'runtime_config' => [
            'command' => ['/usr/bin/python3', '/var/lib/orbit/ai-router/collector.py'],
            'environment_file' => '',
            'environment' => [
                'AI_ROUTER_CACHE_HOST' => '10.44.0.8',
                'CACHE_URL' => 'http://127.0.0.1:4848',
                'CACHE_PORT' => '4848',
                'SERVICE_URL' => 'http://127.0.0.1:4747',
                'SERVICE_PORT' => '4747',
                'AI_ROUTER_MANAGEMENT_KEY' => 'management key',
                'AI_ROUTER_READ_TOKEN' => 'read-token',
            ],
        ],
        'working_directory' => '/var/lib/orbit/ai-router',
        'restart_policy' => 'unless-stopped',
    ]);
    $process->id = 14;
    $target = new ProcessTarget(
        node: new Node(['name' => 'beast']),
        user: 'orbit',
        checkoutPath: '/home/orbit',
        environmentFile: '',
    );

    $unit = new SystemdProcessRenderer()->render($process, $target);

    expect($unit)
        ->toContain('Environment=AI_ROUTER_CACHE_HOST=10.44.0.8')
        ->toContain('Environment=CACHE_URL=http://127.0.0.1:4848')
        ->toContain('Environment=CACHE_PORT=4848')
        ->toContain('Environment=SERVICE_URL=http://127.0.0.1:4747')
        ->toContain('Environment=SERVICE_PORT=4747')
        ->toContain('Environment=AI_ROUTER_MANAGEMENT_KEY=management\\x20key')
        ->toContain('Environment=AI_ROUTER_READ_TOKEN=read-token')
        ->toContain('ExecStart="/usr/bin/python3" "/var/lib/orbit/ai-router/collector.py"')
        ->not->toContain('EnvironmentFile=')
        ->not->toContain('/usr/bin/env')
        ->not->toContain('AI_ROUTER_MANAGEMENT_KEY=management key');

    expect(strpos(haystack: $unit, needle: 'Environment=NODE_USE_SYSTEM_CA=1'))
        ->toBeLessThan(strpos(haystack: $unit, needle: 'Environment=AI_ROUTER_CACHE_HOST='));
});

it('rejects unsafe systemd environment input from persisted configuration', function (array $environment): void {
    $process = new Process([
        'name' => 'ai-router',
        'runtime_config' => [
            'command' => ['/usr/bin/python3', '/var/lib/orbit/ai-router/collector.py'],
            'environment' => $environment,
        ],
        'working_directory' => '/var/lib/orbit/ai-router',
        'restart_policy' => 'never',
    ]);
    $process->id = 15;
    $target = new ProcessTarget(
        node: new Node(['name' => 'beast']),
        user: 'orbit',
        checkoutPath: '/home/orbit',
    );

    expect(fn () => new SystemdProcessRenderer()->render($process, $target))
        ->toThrow(InvalidArgumentException::class, 'environment');
})->with([
    'unsafe name' => [['BAD-NAME' => 'value']],
    'line feed' => [['AI_ROUTER_READ_TOKEN' => "first\nsecond"]],
]);

it('rejects a non-map systemd environment from persisted configuration', function (): void {
    $process = new Process([
        'name' => 'ai-router',
        'runtime_config' => [
            'command' => ['/usr/bin/python3', '/var/lib/orbit/ai-router/collector.py'],
            'environment' => 'AI_ROUTER_READ_TOKEN=unsafe-shape',
        ],
        'working_directory' => '/var/lib/orbit/ai-router',
        'restart_policy' => 'never',
    ]);
    $process->id = 16;
    $target = new ProcessTarget(
        node: new Node(['name' => 'beast']),
        user: 'orbit',
        checkoutPath: '/home/orbit',
    );

    expect(fn () => new SystemdProcessRenderer()->render($process, $target))
        ->toThrow(InvalidArgumentException::class, 'environment');
});

it('rejects a non-absolute executable from persisted runtime configuration', function (): void {
    $process = new Process([
        'name' => 'worker',
        'runtime_config' => [
            'command' => ['php', 'artisan'],
            'environment_file' => '/home/orbit/apps/docs/.env',
        ],
        'working_directory' => '/home/orbit/apps/docs',
        'restart_policy' => 'never',
    ]);
    $process->id = 4;
    $target = new ProcessTarget(
        node: new Node(['name' => 'dev']),
        user: 'orbit',
        checkoutPath: '/home/orbit/apps/docs',
    );

    expect(fn () => new SystemdProcessRenderer()->render($process, $target))
        ->toThrow(InvalidArgumentException::class, 'absolute executable');
});

it('pins the Route development-server origin after the app environment file', function (): void {
    $process = new Process([
        'name' => 'vite',
        'runtime_config' => [
            'command' => ['/usr/bin/npm', 'run', 'dev'],
            'environment_file' => '/tmp/.env',
        ],
        'working_directory' => '/tmp',
        'restart_policy' => 'never',
    ]);
    $process->id = 11;
    $instance = new Instance(['vite_port' => 5173]);
    $target = new ProcessTarget(
        node: new Node(['name' => 'dev']),
        user: 'orbit',
        checkoutPath: '/tmp',
        certificateScope: 'app-instance-6',
        instance: $instance,
        routeDomain: 'tasks.commander.test',
    );

    $unit = new SystemdProcessRenderer()->render(
        $process,
        $target,
        new ManagedUserAccount('orbit', 'orbit', '/home/orbit'),
    );

    expect($unit)
        ->toContain('Environment=ORBIT_DEV_SERVER_ORIGIN=https://tasks.commander.test/__orbit/vite')
        ->toContain('Environment=ORBIT_DEV_SERVER_HOST=tasks.commander.test')
        ->toContain('Environment=ORBIT_DEV_SERVER_PATH=/__orbit/vite')
        ->toContain('Environment=ORBIT_DEV_SERVER_PORT=5173')
        ->toContain(
            'Environment=VITE_DEV_SERVER_CERT=/home/orbit/.orbit/certificates/app-instance-6/current/cert.pem',
        )
        ->toContain(
            '"ORBIT_DEV_SERVER_ORIGIN=https://tasks.commander.test/__orbit/vite"',
        );
});

it('expands only the preset port and gives its owned environment file precedence', function (): void {
    $instance = new Instance(['vite_port' => 5210]);
    $instance->id = 64;
    $process = new Process(['name' => 'assets', 'runtime_config' => ['preset' => 'vp-dev', 'command' => ['/usr/local/bin/vp', 'dev'], 'environment_file' => '/apps/main/.env'], 'working_directory' => '/apps/main', 'restart_policy' => 'on-failure']);
    $process->id = 9;
    $target = new ProcessTarget(node: new Node(['name' => 'test']), user: 'orbit', checkoutPath: '/apps/main', instance: $instance, environmentFile: '/apps/main/.env', routeDomain: 'example.test');
    $unit = new SystemdProcessRenderer()->render($process, $target);
    expect($unit)->toContain('EnvironmentFile=/etc/orbit/vite/app-instance-64.env')->toContain('"--port=${ORBIT_DEV_SERVER_PORT}"')->toContain('"--strictPort"')->toContain('"--host=127.0.0.1"')->not->toContain('"ORBIT_DEV_SERVER_PORT=5210"');
    expect(strpos($unit, 'EnvironmentFile=-/apps/main/.env'))->toBeLessThan(strpos($unit, 'EnvironmentFile=/etc/orbit/vite/app-instance-64.env'));
});

it('projects the assigned ssr port as ORBIT_SSR_PORT and INERTIA_SSR_URL and adds nothing without one', function (?string $routeDomain): void {
    $instance = new Instance(['ssr_port' => 13721]);
    $instance->id = 7;
    $target = new ProcessTarget(node: new Node, user: 'orbit', checkoutPath: '/apps/recall/task-a', instance: $instance, routeDomain: $routeDomain);
    $process = new Process(['name' => 'ssr', 'working_directory' => '/apps/recall/task-a', 'runtime_config' => ['command' => ['/usr/bin/php', 'artisan', 'inertia:start-ssr'], 'environment' => ['INERTIA_SSR_URL' => 'http://127.0.0.1:13719', 'ORBIT_SSR_PORT' => '13719']], 'restart_policy' => 'on-failure']);
    $process->id = 21;

    expect(new SystemdProcessRenderer()->render($process, $target))
        ->toContain('Environment=ORBIT_SSR_PORT=13721')
        ->toContain('Environment=INERTIA_SSR_URL=http://127.0.0.1:13721')
        ->toContain('"ORBIT_SSR_PORT=13721" "INERTIA_SSR_URL=http://127.0.0.1:13721"')
        ->not->toContain('13719');

    $instance->ssr_port = null;
    expect(new SystemdProcessRenderer()->render($process, $target))
        ->toContain('Environment=INERTIA_SSR_URL=http://127.0.0.1:13719')
        ->toContain('Environment=ORBIT_SSR_PORT=13719')
        ->not->toContain('13721');
})->with(['with a Route' => ['recall-task-a.test'], 'without a Route' => [null]]);
