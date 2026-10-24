<?php

declare(strict_types=1);

use App\Domain\AgentView\AgentViewConverger;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Certificates\GatewayCertificatePaths;
use App\Domain\Gateway\GatewayServiceStartHook;
use App\Domain\GatewayReleases\GatewayReleaseUnitConverger;
use App\Domain\Hibernation\RuntimeHibernatorConverger;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilder;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildLock;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Caddy\Build\NodeCaddyTransport;
use App\Infrastructure\Files\ProtectedFileWriter;
use App\Infrastructure\Gateway\GatewayCaddyConfigRenderer;
use App\Infrastructure\Gateway\GatewayCheckoutAccessConverger;
use App\Infrastructure\Gateway\GatewayFpmConfigRenderer;
use App\Infrastructure\Gateway\GatewayWebDirectoryConverger;
use App\Infrastructure\Gateway\NativeGatewayCaddyInstaller;
use App\Infrastructure\Gateway\NativeGatewayCertificatePublisher;
use App\Infrastructure\Gateway\NativeGatewayFpmConverger;
use App\Infrastructure\Gateway\NativeGatewayWebConverger;
use App\Infrastructure\Nodes\AgentCaddyPackage;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Orbit\Extensions\Documents\Infrastructure\CleanupGate;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\Support\FakeAgentCommandRunner;

it('installs and executes the FPM pre-start gate for a restart or refusal', function (string $ambientEnvironment, string $boundary): void {
    $previousEnvironment = $_ENV['APP_ENV'] ?? null;
    [$converger, $processes, $issuer, $orbitHome] = gateway_web_converger();
    $root = sys_get_temp_dir().'/orbit-cleanup-startup-'.Str::uuid();
    $filesystem = new Filesystem;
    $filesystem->makeDirectory($root.'/bin', 0700, true);
    // Model the existing trusted /run parent. install -d may create missing intermediate parents as 0775.
    $filesystem->makeDirectory($root.'/run', 0755);
    config(['orbit.gateway_checkout' => base_path()]);
    $filesystem->put($root.'/bin/install', "#!/bin/bash\nargs=()\nwhile (( $# )); do case \"\$1\" in -o|-g) shift 2;; *) args+=(\"\$1\"); shift;; esac; done\nexec /usr/bin/install \"\${args[@]}\"\n");
    $filesystem->put($root.'/bin/chown', "#!/bin/sh\nexit 0\n");
    $filesystem->put($root.'/bin/systemctl', "#!/bin/sh\n[ \"\$1\" = daemon-reload ]\n");
    $filesystem->put($root.'/bin/runuser', "#!/bin/sh\n[ \"\$1 \$2 \$3\" = '-u orbit --' ] || exit 1\nshift 3\nexec \"\$@\"\n");
    foreach (['install', 'chown', 'systemctl', 'runuser'] as $binary) {
        chmod($root.'/bin/'.$binary, 0700);
    }
    $runtime = $root.'/run/orbit/project-documents';
    config(['documents.cleanup_runtime' => $runtime]);
    // Reproduce a coordinator's group-writable umask for native installation subprocesses.
    $previousUmask = umask(0002);
    try {
        $converger->converge(gateway_web_node(), 'gateway.orbit', '10.44.0.1');
        $calls = Collection::make($processes->calls);
        $install = $calls->first(static fn (ProcessInvocation $call): bool => in_array('orbit-document-cleanup.conf', $call->arguments, true));
        expect($install)->toBeInstanceOf(ProcessInvocation::class);
        $invalidation = $calls->search(static fn (ProcessInvocation $call): bool => $call->arguments === ['sudo', '/etc/orbit/project-document-cleanup-start']);
        $activation = $calls->search(static fn (ProcessInvocation $call): bool => $call->arguments === ['sudo', 'systemctl', 'reload-or-restart', 'php8.5-fpm']);
        expect($invalidation)->toBeInt();
        expect($activation)->toBeInt()->toBeGreaterThan($invalidation);
        $arguments = str_replace(['/etc/orbit', '/etc/systemd', '/run/orbit', '/usr/sbin/runuser'], [$root.'/etc/orbit', $root.'/etc/systemd', $root.'/run/orbit', $root.'/bin/runuser'], array_slice($install->arguments, 1));
        // Subprocesses inherit an environment that need not match the booted test application's config.
        $_ENV['APP_ENV'] = $ambientEnvironment;
        $environment = ['APP_ENV' => 'testing', 'PATH' => $root.'/bin:'.getenv('PATH'), 'ORBIT_DOCUMENT_CLEANUP_RUNTIME' => $runtime];
        $installer = new Process($arguments, env: $environment);
        $installer->setInput($install->input);
        expect($installer->run())->toBe(0, $installer->getErrorOutput());
        $unit = file_get_contents($root.'/etc/systemd/system/php8.5-fpm.service.d/orbit-document-cleanup.conf');
        preg_match('/^ExecStartPre=\\+(.+)$/m', $unit, $matches);
        expect($matches)->toHaveKey(1);
        $start = new Process(['bash', '-euc', '"$1" && printf api-started', '--', $matches[1]], env: $environment);
        expect($start->run())->toBe(0, $start->getOutput().$start->getErrorOutput());
        expect($start->getOutput())->toContain('api-started');
        $gate = app(CleanupGate::class);
        $first = $gate->status()->generation;
        expect($gate->status()->state)->toBe('paused');
        if ($boundary === 'permit') {
            $state = json_decode(file_get_contents($runtime.'/generation.json'), true, flags: JSON_THROW_ON_ERROR);
            $state['report_id'] = str_repeat('b', 64);
            $state['report_sha256'] = str_repeat('c', 64);
            file_put_contents($runtime.'/generation.json', json_encode($state));
            file_put_contents($runtime.'/permit.json', json_encode($state));
            chmod($runtime.'/permit.json', 0600);
            expect($gate->status()->state)->toBe('running');
            expect($start->run())->toBe(0, $start->getErrorOutput());
            expect($gate->status()->generation)->not->toBe($first);
            expect($gate->status()->reportId)->toBeNull();
            expect(file_exists($runtime.'/permit.json'))->toBeFalse();
        } else {
            [$path, $mode] = match ($boundary) {
                'parent' => [$root.'/run', 0775],
                'lock' => [$runtime.'/execution.lock', 0000],
            };
            chmod($path, $mode);
            expect($start->run())->toBe(1);
            expect($start->getOutput())->not->toContain('api-started');
            expect($start->getOutput())->toContain('project_documents.cleanup_state_unavailable');
        }
    } finally {
        umask($previousUmask);
        $filesystem->deleteDirectory($root);
        $filesystem->deleteDirectory($orbitHome);
        if ($previousEnvironment === null) {
            unset($_ENV['APP_ENV']);
        } else {
            $_ENV['APP_ENV'] = $previousEnvironment;
        }
    }
})->with(['testing', 'production'])->with([
    'valid permit' => 'permit',
    'unsafe parent' => 'parent',
    'unreadable lock' => 'lock',
]);

it('refuses FPM reload or restart when cleanup invalidation fails during convergence', function (): void {
    [$converger, $processes, , $orbitHome] = gateway_web_converger(failure: 'cleanup-invalidation');
    try {
        expect(fn () => $converger->converge(gateway_web_node(), 'gateway.orbit', '10.44.0.1'))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)->toBe('gateway-fpm-start-hook');
                expect($exception->errorCode)->toBe('gateway.fpm_start_failed');
            });
        expect(Collection::make($processes->calls)->contains(static fn (ProcessInvocation $call): bool => $call->arguments === ['sudo', 'systemctl', 'reload-or-restart', 'php8.5-fpm']))->toBeFalse();
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('publishes complete validated FPM Caddy and certificate configurations through atomic switches', function (): void {
    [$converger, $processes, $issuer, $orbitHome, $hibernator, $agentView, $releaseUnits] = gateway_web_converger();

    try {
        $converger->converge(gateway_web_node(), 'gateway.orbit', '10.44.0.1');
        $calls = Collection::make($processes->calls);
        $commands = $calls->map(static fn (ProcessInvocation $invocation): array => $invocation->arguments);
        $fpmStage = $calls->first(
            static fn (ProcessInvocation $invocation): bool => (
                array_slice(array: $invocation->arguments, offset: 0, length: 4) === ['sudo', 'bash', '-seu', '--']
                && str_ends_with($invocation->arguments[4] ?? '', '/generated/gateway/php-fpm-pool.conf')
            ),
        );
        $fpmCandidateDirectory = $fpmStage?->arguments[5] ?? null;
        $fpmCandidateMain = $fpmStage?->arguments[6] ?? null;
        $fpmCandidatePool = $fpmStage?->arguments[7] ?? null;
        $fpmValidationIndex = $commands->search(
            static fn (array $arguments): bool => $arguments === [
                'sudo',
                'php-fpm8.5',
                '--test',
                '--fpm-config',
                $fpmCandidateMain,
            ],
        );
        $fpmPublishIndex = $commands->search(
            static fn (array $arguments): bool => $arguments === [
                'sudo',
                'mv',
                '-f',
                '--',
                $fpmCandidatePool,
                '/etc/php/8.5/fpm/pool.d/orbit-gateway.conf',
            ],
        );
        $certificatePublishIndex = $commands->search(['orbit-agent', 'command', 'gateway.publish-certificate']);
        $buildIndex = $calls->search(gateway_web_is_build(...));
        $certificatePublish = $commands->get($certificatePublishIndex);
        $pushed = gateway_web_pushed($calls->get($buildIndex));

        expect($issuer->calls)
            ->toBe([['hostname' => 'gateway.orbit', 'address' => '10.44.0.1']])
            ->and(file_get_contents($orbitHome.'/generated/gateway/php-fpm-pool.conf'))
            ->toContain(
                '[orbit-gateway]',
                'listen = /run/php/orbit-gateway.sock',
                'listen.group = caddy',
                'listen.mode = 0660',
                'request_terminate_timeout = 600s',
                'php_admin_value[max_execution_time] = 600',
                'php_admin_value[opcache.validate_timestamps] = 1',
            )
            ->and($pushed)
            ->toStartWith(NodeCaddyfileRenderer::Marker)
            ->toContain(
                '# orbit: gateway gateway.orbit',
                'gateway.orbit, 10.44.0.1',
                'bind 10.44.0.1',
                'root * /home/orbit/orbit-gateway/public',
                'tls /etc/caddy/orbit-cert-current/gateway.pem /etc/caddy/orbit-cert-current/gateway.key',
                'php_fastcgi unix//run/php/orbit-gateway.sock',
                'resolve_root_symlink',
                'dial_timeout 10s',
                'read_timeout 600s',
                'write_timeout 600s',
                'flush_interval 1ms',
                'root * /home/orbit/web/current',
                'reverse_proxy https://10.44.0.1',
            )
            ->and(file_exists($orbitHome.'/generated/gateway/Caddyfile'))
            ->toBeFalse()
            ->and(fileperms($orbitHome.'/generated/gateway/php-fpm-pool.conf') & 0o777)
            ->toBe(0o644)
            ->and(fileperms($orbitHome.'/generated/gateway') & 0o777)
            ->toBe(0o700)
            ->and($fpmCandidateDirectory)
            ->toMatch('#^/etc/php/8\.5/fpm/orbit-candidates/[a-f0-9]{16}$#')
            ->and($fpmCandidateMain)
            ->toBe($fpmCandidateDirectory.'/php-fpm.conf')
            ->and($fpmCandidatePool)
            ->toBe($fpmCandidateDirectory.'/pool.d/orbit-gateway.conf')
            ->and($fpmStage?->input)
            ->toContain(
                'candidate_directory=$2',
                'candidate_main=$3',
                'candidate_pool=$4',
                'for pool in /etc/php/8.5/fpm/pool.d/*.conf',
                'if [ "$pool_name" = orbit-gateway.conf ]; then',
                'cp --preserve=mode,ownership -- "$pool" "$candidate_pool_directory/$pool_name"',
                'install -o root -g root -m 0644 -- "$replacement" "$candidate_pool"',
                'replacement_count',
            )
            ->and($fpmValidationIndex)
            ->toBeInt()
            ->toBeLessThan($fpmPublishIndex)
            ->and($certificatePublishIndex)
            ->toBeInt()
            ->toBeLessThan($buildIndex)
            ->and($fpmPublishIndex)
            ->toBeLessThan($buildIndex)
            ->and($calls->filter(gateway_web_is_build(...)))
            ->toHaveCount(1)
            ->and($calls->contains(
                static fn (ProcessInvocation $invocation): bool => str_contains($invocation->input ?? '', 'orbit.d')
                    || str_contains($invocation->input ?? '', 'fragments/*.caddy'),
            ))
            ->toBeFalse()
            ->and($certificatePublish)
            ->toBeArray()
            ->and($commands->contains(
                static fn (array $arguments): bool => (
                    in_array(needle: '/etc/caddy/orbit-cert-current/gateway.pem', haystack: $arguments, strict: true)
                    || in_array(needle: '/etc/caddy/orbit-cert-current/gateway.key', haystack: $arguments, strict: true)
                ),
            ))
            ->toBeFalse()
            ->and($commands->contains(['orbit-agent', 'command', 'gateway.prepare-checkout']))
            ->toBeTrue()
            ->and($commands->contains(['orbit-agent', 'command', 'gateway.prepare-web-directory']))
            ->toBeTrue()
            ->and(json_decode($calls->get($certificatePublishIndex)->input, true, flags: JSON_THROW_ON_ERROR))
            ->toBe(['certificate' => 'CERTIFICATE', 'key' => 'PRIVATE KEY', 'root_certificate' => 'ROOT CERTIFICATE'])
            ->and($hibernator->calls)
            ->toBe(1)
            ->and($agentView->calls)
            ->toBe(1)
            ->and($releaseUnits->calls)
            ->toBe(1);
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('keeps staged FPM candidates bound to their invocation under contention', function (): void {
    $processes = new GatewayFpmContentionProcessRunner;
    $primary = new NativeGatewayFpmConverger($processes, app(RemoteProgramCatalog::class), [...app()->tagged(GatewayServiceStartHook::class)]);
    $processes->contender = new NativeGatewayFpmConverger($processes, app(RemoteProgramCatalog::class), [...app()->tagged(GatewayServiceStartHook::class)]);

    $primary->converge('/tmp/primary-gateway-pool.conf');

    $calls = Collection::make($processes->calls);
    $commands = $calls->map(static fn (ProcessInvocation $invocation): array => $invocation->arguments);
    $primaryStage = $calls->firstOrFail(
        static fn (ProcessInvocation $invocation): bool => (
            ($invocation->arguments[4] ?? null) === '/tmp/primary-gateway-pool.conf'
        ),
    );
    $contenderStage = $calls->firstOrFail(
        static fn (ProcessInvocation $invocation): bool => (
            ($invocation->arguments[4] ?? null) === '/tmp/contending-gateway-pool.conf'
        ),
    );
    [$primaryDirectory, $primaryMain, $primaryPool] = array_slice($primaryStage->arguments, 5, 3);
    [$contenderDirectory, $contenderMain, $contenderPool] = array_slice($contenderStage->arguments, 5, 3);
    $contenderCleanupIndex = $commands->search(['sudo', 'rm', '-rf', '--', $contenderDirectory]);
    $primaryPublishIndex = $commands->search(
        ['sudo', 'mv', '-f', '--', $primaryPool, '/etc/php/8.5/fpm/pool.d/orbit-gateway.conf'],
    );

    expect($primaryDirectory)
        ->not->toBe($contenderDirectory)->and($primaryMain)
        ->not->toBe($contenderMain)->and($primaryPool)
        ->not->toBe($contenderPool)->and($commands->contains([
            'sudo',
            'php-fpm8.5',
            '--test',
            '--fpm-config',
            $primaryMain,
        ]))->toBeTrue()->and($commands->contains([
            'sudo',
            'php-fpm8.5',
            '--test',
            '--fpm-config',
            $contenderMain,
        ]))->toBeTrue()->and($commands->contains([
            'sudo',
            'mv',
            '-f',
            '--',
            $contenderPool,
            '/etc/php/8.5/fpm/pool.d/orbit-gateway.conf',
        ]))->toBeTrue()->and($contenderCleanupIndex)->toBeInt()->toBeLessThan(
            $primaryPublishIndex,
        )->and($commands->contains(['sudo', 'rm', '-rf', '--', $primaryDirectory]))->toBeTrue();
});

it('publishes one complete gateway file from distinct candidates under contention', function (): void {
    $directory = sys_get_temp_dir().'/orbit-protected-writer-'.Str::uuid();
    $path = $directory.'/generated/gateway/Caddyfile';
    $markerDirectory = $directory.'/markers';
    $releasePath = $markerDirectory.'/release';
    $characters = ['A', 'B', 'C', 'D'];
    $bytes = 1024 * 1024;
    $processes = [];

    mkdir($markerDirectory, permissions: 0o700, recursive: true);

    try {
        foreach ($characters as $index => $character) {
            $process = protected_file_writer_process($path, $character, $bytes, "{$markerDirectory}/staged-{$index}", $releasePath);
            $process->start();
            $processes[] = $process;
        }

        // Every writer holds its complete candidate until all of them have one, so the
        // candidates exist side by side whatever the scheduler does.
        foreach (array_keys($characters) as $index) {
            protected_file_writer_wait_until(
                static fn (): bool => is_file("{$markerDirectory}/staged-{$index}"),
                $processes,
                static fn (): string => "staged marker {$index}",
            );
        }

        $candidates = protected_file_writer_candidates($path);
        $candidateContents = array_map(file_get_contents(...), $candidates);
        sort($candidateContents);

        expect($candidates)->toHaveCount(4)
            ->and($candidateContents)->toBe(array_map(static fn (string $character): string => str_repeat($character, $bytes), $characters));

        file_put_contents($releasePath, 'release');

        foreach ($processes as $process) {
            protected_file_writer_wait_for_success($process);
        }

        $contents = file_get_contents($path);

        expect(is_string($contents))->toBeTrue();

        if (! is_string($contents) || $contents === '') {
            throw new RuntimeException('The contended protected file was not published.');
        }

        expect(in_array($contents[0], $characters, true))->toBeTrue();
        expect($contents)->toBe(str_repeat($contents[0], $bytes));
        expect(protected_file_writer_candidates($path))->toBe([]);
        expect(fileperms($path) & 0o777)->toBe(0o644);
        expect(fileperms(dirname($path)) & 0o777)->toBe(0o700);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0.1, 9);
            }
        }

        new Filesystem()->deleteDirectory($directory);
    }
});

it('preserves a refused destination and cleans only its protected-file candidate', function (): void {
    $directory = sys_get_temp_dir().'/orbit-protected-writer-'.Str::uuid();
    $path = $directory.'/generated/gateway/Caddyfile';
    $unrelatedCandidate = $path.'.candidate.other-invocation';
    mkdir($path, permissions: 0o700, recursive: true);
    file_put_contents($path.'/original', 'original');
    file_put_contents($unrelatedCandidate, 'other');
    chmod($unrelatedCandidate, 0o600);

    try {
        set_error_handler(static fn (): bool => true);

        try {
            expect(fn () => new ProtectedFileWriter()->put($path, 'replacement', 0o644))
                ->toThrow(RuntimeException::class, "Could not install protected file [{$path}].");
        } finally {
            restore_error_handler();
        }

        expect(file_get_contents($path.'/original'))->toBe('original');
        expect(protected_file_writer_siblings($path))->toBe([$unrelatedCandidate]);
        expect(file_get_contents($unrelatedCandidate))->toBe('other');
        expect(fileperms($unrelatedCandidate) & 0o777)->toBe(0o600);
    } finally {
        new Filesystem()->deleteDirectory($directory);
    }
});

it('adapts Caddy to a listener bound only to the gateway WireGuard address', function (): void {
    $configuration = new GatewayCaddyConfigRenderer()->render(
        hostname: 'gateway.orbit',
        wireguardIp: '10.44.0.1',
        checkoutPath: '/home/orbit/orbit-gateway',
        webRoot: '/home/orbit/web',
    );
    $result = caddy_adapt($configuration);
    /** @var array{apps: array{http: array{servers: array<string, array{listen: list<string>}>}}} $adapted */
    $adapted = json_decode(json: $result->stdout, associative: true, flags: JSON_THROW_ON_ERROR);
    $listeners = Collection::make($adapted['apps']['http']['servers'])
        ->flatMap(static fn (array $server): array => $server['listen'])
        ->values()
        ->all();

    expect($result->succeeded())
        ->toBeTrue()
        ->and($listeners)
        ->toBe(['10.44.0.1:443'])
        ->not->toContain(':443', '0.0.0.0:443');
});

it('preserves live FPM disk and does not reload when complete effective validation fails', function (): void {
    [$converger, $processes, , $orbitHome] = gateway_web_converger(failure: 'fpm-validation');

    try {
        expect(fn () => $converger->converge(gateway_web_node(), 'gateway.orbit', '10.44.0.1'))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)
                    ->toBe('gateway-fpm-validate')
                    ->and($exception->errorCode)
                    ->toBe('gateway.fpm_config_invalid')
                    ->and($exception->result?->stderr)
                    ->toBe('duplicate pool listener');
            });

        $calls = Collection::make($processes->calls);
        $commands = $calls->map(static fn (ProcessInvocation $invocation): array => $invocation->arguments);
        $fpmStage = $calls->firstOrFail(
            static fn (ProcessInvocation $invocation): bool => (
                array_slice(array: $invocation->arguments, offset: 0, length: 4) === ['sudo', 'bash', '-seu', '--']
                && str_ends_with($invocation->arguments[4] ?? '', '/generated/gateway/php-fpm-pool.conf')
            ),
        );
        $candidateDirectory = $fpmStage->arguments[5];
        $candidatePool = $fpmStage->arguments[7];

        expect($commands->contains([
            'sudo',
            'mv',
            '-f',
            '--',
            $candidatePool,
            '/etc/php/8.5/fpm/pool.d/orbit-gateway.conf',
        ]))
            ->toBeFalse()
            ->and($commands->contains(['sudo', 'rm', '-rf', '--', $candidateDirectory]))
            ->toBeTrue()
            ->and($commands->contains(['sudo', 'rm', '-rf', '--', '/etc/php/8.5/fpm/orbit-candidates']))
            ->toBeFalse()
            ->and($commands->contains(['sudo', 'systemctl', 'reload-or-restart', 'php8.5-fpm']))
            ->toBeFalse();
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('installs Caddy from the pinned source and orders it after WireGuard before any Caddy step', function (): void {
    [$converger, $processes, , $orbitHome] = gateway_web_converger();

    try {
        $converger->converge(gateway_web_node(), 'gateway.orbit', '10.44.0.1');
        $calls = Collection::make($processes->calls);
        $commands = $calls->map(static fn (ProcessInvocation $invocation): array => $invocation->arguments);
        $installIndex = $commands->search(['orbit-agent', 'command', 'caddy.install-package']);
        $orderingIndex = $commands->search(['orbit-agent', 'command', 'caddy.order-after-vpn']);
        $firstCaddyGroupIndex = $commands->search(
            static fn (array $arguments): bool => $arguments === ['orbit-agent', 'command', 'gateway.prepare-web-directory'],
        );
        $reloadIndex = $commands->search(['orbit-agent', 'command', 'gateway.publish-certificate']);
        $validationIndex = $calls->search(gateway_web_is_build(...));
        $install = $calls->get($installIndex);
        $ordering = $calls->get($orderingIndex);
        $orderingInput = $ordering->input ?? '';

        expect($installIndex)
            ->toBeInt()
            ->and($orderingIndex)
            ->toBe($installIndex + 1)
            ->and($firstCaddyGroupIndex)
            ->toBeGreaterThan($orderingIndex)
            ->and($reloadIndex)
            ->toBeGreaterThan($orderingIndex)
            ->and($validationIndex)
            ->toBeGreaterThan($reloadIndex)
            ->and($commands->filter(
                static fn (array $arguments): bool => $arguments === ['orbit-agent', 'command', 'caddy.order-after-vpn'],
            ))
            ->toHaveCount(1)
            ->and(json_decode($install->input, true, flags: JSON_THROW_ON_ERROR))
            ->toBe(AgentCaddyPackage::input())
            ->and(json_decode($orderingInput, true, flags: JSON_THROW_ON_ERROR))
            ->toBe([]);
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('repeats the same idempotent install step on every web convergence', function (): void {
    [$converger, $processes, , $orbitHome] = gateway_web_converger();

    try {
        $converger->converge(gateway_web_node(), 'gateway.orbit', '10.44.0.1');
        $first = array_slice(array: $processes->calls, offset: 0, length: 3);
        $processes->calls = [];
        $converger->converge(gateway_web_node(), 'gateway.orbit', '10.44.0.1');
        $second = array_slice(array: $processes->calls, offset: 0, length: 3);

        expect($second)
            ->toEqual($first)
            ->and($first[0]->arguments)
            ->toBe(['orbit-agent', 'command', 'gateway.check-checkout'])
            ->and($first[1]->arguments)
            ->toBe(['orbit-agent', 'command', 'caddy.install-package'])
            ->and($first[2]->arguments)
            ->toBe(['orbit-agent', 'command', 'caddy.order-after-vpn']);
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('stops before any Caddy or certificate step when Caddy cannot be installed', function (): void {
    [$converger, $processes, $issuer, $orbitHome, $hibernator, $agentView, $releaseUnits] = gateway_web_converger(failure: 'caddy-install');

    try {
        expect(fn () => $converger->converge(gateway_web_node(), 'gateway.orbit', '10.44.0.1'))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)
                    ->toBe('gateway-caddy-install')
                    ->and($exception->errorCode)
                    ->toBe('gateway.caddy_install_failed')
                    ->and($exception->result?->stderr)
                    ->toBe('The caddy candidate does not come from the pinned Orbit source.');
            });

        $commands = Collection::make($processes->calls)
            ->map(static fn (ProcessInvocation $invocation): array => $invocation->arguments);

        expect($commands->last())
            ->toBe(['orbit-agent', 'command', 'caddy.install-package'])
            ->and($commands->contains(['orbit-agent', 'command', 'caddy.order-after-vpn']))
            ->toBeFalse()
            ->and($commands->contains(
                static fn (array $arguments): bool => in_array(needle: 'orbit:caddy', haystack: $arguments, strict: true)
                    || in_array(needle: 'caddy', haystack: array_slice(array: $arguments, offset: 1, length: 1), strict: true)
                    || end($arguments) === '/etc/caddy/Caddyfile',
            ))
            ->toBeFalse()
            ->and(Collection::make($processes->calls)->contains(gateway_web_is_build(...)))
            ->toBeFalse()
            ->and($issuer->calls)
            ->toBeEmpty()
            ->and($hibernator->calls)
            ->toBe(0)
            ->and($agentView->calls)
            ->toBe(0)
            ->and($releaseUnits->calls)
            ->toBe(0);
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('keeps its error codes and names the build stage and Caddy message when the Node Caddy build fails', function (string $stage, string $step, string $errorCode): void {
    [$converger, $processes, , $orbitHome, $hibernator] = gateway_web_converger(failure: "caddy-build:{$stage}");

    try {
        expect(fn () => $converger->converge(gateway_web_node(), 'gateway.orbit', '10.44.0.1'))
            ->toThrow(function (NodeProvisioningException $exception) use ($stage, $step, $errorCode): void {
                expect($exception->step)
                    ->toBe($step)
                    ->and($exception->errorCode)
                    ->toBe($errorCode)
                    ->and($exception->getMessage())
                    ->toBe("The Caddy build for Node [gateway] failed at stage [{$stage}]: Error: aggregate route conflict");
            });

        expect(Collection::make($processes->calls)->filter(gateway_web_is_build(...)))
            ->toHaveCount(1)
            ->and($hibernator->calls)
            ->toBe(0);
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
})->with([
    'validation' => ['validate', 'gateway-caddy-validate', 'gateway.caddy_config_invalid'],
    'reload' => ['reload', 'gateway-caddy-reload', 'gateway.caddy_start_failed'],
    'swap' => ['swap', 'gateway-caddy-publish', 'gateway.caddy_config_install_failed'],
]);

it('stops web convergence when the certificate command refuses publication', function (): void {
    [$converger, $processes, , $orbitHome] = gateway_web_converger(failure: 'certificate-publication');

    try {
        expect(fn () => $converger->converge(gateway_web_node(), 'gateway.orbit', '10.44.0.1'))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)
                    ->toBe('gateway-certificate-publish')
                    ->and($exception->errorCode)
                    ->toBe('gateway.certificate_publish_failed');
            });

        $commands = Collection::make($processes->calls)
            ->map(static fn (ProcessInvocation $invocation): array => $invocation->arguments);

        expect($commands->contains(['orbit-agent', 'command', 'gateway.publish-certificate']))
            ->toBeTrue()
            ->and($commands->contains(['sudo', 'systemctl', 'reload-or-restart', 'php8.5-fpm']))
            ->toBeFalse()
            ->and($commands->contains(['orbit-agent', 'command', 'caddy.publish']))
            ->toBeFalse();
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('rejects a checkout outside the configured Orbit home before certificate or sudo work', function (): void {
    [$converger, $processes, $issuer, $orbitHome] = gateway_web_converger(
        checkoutPath: '/srv/external/orbit-gateway',
    );

    try {
        expect(fn () => $converger->converge(gateway_web_node(), 'gateway.orbit', '10.44.0.1'))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)
                    ->toBe('gateway-checkout-validate')
                    ->and($exception->errorCode)
                    ->toBe('gateway.checkout_invalid');
            })
            ->and($issuer->calls)
            ->toBeEmpty()
            ->and(array_map(static fn (ProcessInvocation $call): array => $call->arguments, $processes->calls))
            ->toBe([['orbit-agent', 'command', 'gateway.check-checkout']]);
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

/**
 * Starts a writer that holds its written candidate before it installs it: the script shadows
 * `rename` in the writer's namespace, marks the candidate as staged, and waits for the release.
 */
function protected_file_writer_process(
    string $path,
    string $character,
    int $bytes,
    string $stagedPath,
    string $releasePath,
): Process {
    $script = <<<'SCRIPT'
        namespace App\Infrastructure\Files {
            function rename(string $from, string $to): bool
            {
                file_put_contents($GLOBALS['argv'][5], 'staged');

                while (! is_file($GLOBALS['argv'][6])) {
                    usleep(1_000);
                }

                return \rename($from, $to);
            }
        }

        namespace {
            require $argv[1].'/vendor/autoload.php';

            new App\Infrastructure\Files\ProtectedFileWriter()->put(
                $argv[2],
                str_repeat($argv[3], (int) $argv[4]),
                0o644,
            );
        }
        SCRIPT;
    $process = new Process([
        PHP_BINARY,
        '-r',
        $script,
        base_path(),
        $path,
        $character,
        (string) $bytes,
        $stagedPath,
        $releasePath,
    ]);
    $process->setTimeout(30);

    return $process;
}

/** @return list<string> */
function protected_file_writer_candidates(string $path): array
{
    $candidates = glob($path.'.candidate.*');

    if (! is_array($candidates)) {
        return [];
    }

    sort($candidates);

    return $candidates;
}

/** @return list<string> */
function protected_file_writer_siblings(string $path): array
{
    $siblings = glob($path.'.*');

    if (! is_array($siblings)) {
        return [];
    }

    sort($siblings);

    return $siblings;
}

function protected_file_writer_wait_for_success(Process $process): void
{
    $exitCode = $process->wait();

    if ($exitCode !== 0) {
        throw new RuntimeException(
            "Protected-file writer failed with exit code {$exitCode}: {$process->getErrorOutput()}",
        );
    }
}

/** @param list<Process> $processes */
function protected_file_writer_wait_until(Closure $condition, array $processes, Closure $state): void
{
    while (! $condition()) {
        $running = false;
        $failure = null;
        $writerStates = [];

        foreach ($processes as $index => $process) {
            try {
                $process->checkTimeout();
            } catch (ProcessTimedOutException $exception) {
                $failure = "Writer {$index} exceeded its {$exception->getExceededTimeout()} second lifetime.";
            }

            if ($process->isRunning()) {
                $running = true;
            } elseif (! $process->isSuccessful()) {
                $failure ??= 'A protected-file writer failed before the required state was observed.';
            }

            $writerStates[] = sprintf(
                'writer %d: status=%s, exit=%s, stderr=%s',
                $index,
                $process->getStatus(),
                $process->getExitCode() ?? 'none',
                $process->getErrorOutput(),
            );
        }

        if ($failure !== null || ! $running) {
            throw new RuntimeException(
                $state().'. '.($failure ?? 'All protected-file writers exited before the required state was observed.')
                .' '.implode('; ', $writerStates),
            );
        }

        usleep(1_000);
    }
}

/**
 * @return array{
 *     NativeGatewayWebConverger,
 *     object&ProcessRunner,
 *     object&GatewayCertificateIssuer,
 *     string,
 *     RecordingRuntimeHibernatorConverger,
 *     RecordingAgentViewConverger
 * }
 */
function gateway_web_converger(?string $failure = null, string $checkoutPath = '/home/orbit/orbit-gateway'): array
{
    $orbitHome = sys_get_temp_dir().'/orbit-gateway-web-'.Str::uuid();
    mkdir(directory: $orbitHome.'/ca/gateway-current', permissions: 0o700, recursive: true);
    file_put_contents(filename: $orbitHome.'/ca/gateway-current/gateway.key', data: 'PRIVATE KEY');
    file_put_contents(filename: $orbitHome.'/ca/gateway-current/gateway.pem', data: 'CERTIFICATE');
    file_put_contents($orbitHome.'/ca/root.pem', 'ROOT CERTIFICATE');
    $issuer = new class($orbitHome) implements GatewayCertificateIssuer
    {
        /** @var list<array{hostname: string, address: string}> */
        public array $calls = [];

        public function __construct(
            private readonly string $orbitHome,
        ) {}

        public function issue(string $hostname, string $wireguardIp): GatewayCertificatePaths
        {
            $this->calls[] = ['hostname' => $hostname, 'address' => $wireguardIp];

            return new GatewayCertificatePaths(
                privateKeyPath: $this->orbitHome.'/ca/gateway-current/gateway.key',
                certificatePath: $this->orbitHome.'/ca/gateway-current/gateway.pem',
            );
        }
    };
    $processes = new class($failure) implements ProcessRunner
    {
        /** @var list<ProcessInvocation> */
        public array $calls = [];

        public function __construct(
            private readonly ?string $failure,
        ) {}

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->calls[] = $invocation;
            $arguments = $invocation->arguments;

            if ($this->failure === 'cleanup-invalidation' && $arguments === ['sudo', '/etc/orbit/project-document-cleanup-start']) {
                return new CommandResult(1, '', 'Document cleanup state is unavailable.', 2, false);
            }

            if (
                $this->failure === 'caddy-install'
                && $arguments === ['orbit-agent', 'command', 'caddy.install-package']
            ) {
                return new CommandResult(1, '', 'The caddy candidate does not come from the pinned Orbit source.', 2, false);
            }

            if (
                $this->failure === 'fpm-validation'
                && array_slice(array: $arguments, offset: 0, length: 4) === [
                    'sudo',
                    'php-fpm8.5',
                    '--test',
                    '--fpm-config',
                ]
            ) {
                return new CommandResult(1, '', 'duplicate pool listener', 2, false);
            }

            if (is_string($this->failure) && str_starts_with($this->failure, 'caddy-build:') && gateway_web_is_build($invocation)) {
                $stage = substr($this->failure, strlen('caddy-build:'));

                return new CommandResult(1, '', "Error: aggregate route conflict\norbit-caddy-build-stage={$stage}\n", 2, false);
            }

            return new CommandResult(0, '', '', 2, false);
        }
    };

    config()->set('orbit.gateway_checkout', $checkoutPath);
    config()->set('orbit.gateway_web', '/home/orbit/web');
    gateway_web_node();

    return [
        new NativeGatewayWebConverger(
            certificates: $issuer,
            fpmRenderer: new GatewayFpmConfigRenderer,
            files: new ProtectedFileWriter,
            checkout: new GatewayCheckoutAccessConverger(
                new FakeAgentCommandRunner()
                    ->answer('gateway.check-checkout', static function (array $input) use ($processes): array {
                        $processes->run(new ProcessInvocation(['orbit-agent', 'command', 'gateway.check-checkout'], input: json_encode($input, JSON_THROW_ON_ERROR)));
                        if ($input['path'] === '/srv/external/orbit-gateway') {
                            throw new AgentCommandRefused('gateway.check-checkout', 'gateway.checkout_invalid', 'Unsafe checkout.');
                        }

                        return ['changed' => false];
                    })
                    ->answer('gateway.prepare-checkout', static function (array $input) use ($processes): array {
                        $processes->run(new ProcessInvocation(['orbit-agent', 'command', 'gateway.prepare-checkout'], input: json_encode($input, JSON_THROW_ON_ERROR)));

                        return ['changed' => true];
                    }),
                $checkoutPath,
            ),
            webDirectory: new GatewayWebDirectoryConverger(
                new FakeAgentCommandRunner()->answer('gateway.prepare-web-directory', static function (array $input) use ($processes): array {
                    $processes->run(new ProcessInvocation(['orbit-agent', 'command', 'gateway.prepare-web-directory'], input: json_encode($input, JSON_THROW_ON_ERROR)));

                    return ['changed' => true];
                }),
                '/home/orbit/web',
            ),
            certificatePublisher: new NativeGatewayCertificatePublisher(
                new FakeAgentCommandRunner()->answer('gateway.publish-certificate', static function (array $input) use ($processes, $failure): array {
                    $processes->run(new ProcessInvocation(['orbit-agent', 'command', 'gateway.publish-certificate'], input: json_encode($input, JSON_THROW_ON_ERROR)));
                    if ($failure === 'certificate-publication') {
                        throw new AgentCommandRefused('gateway.publish-certificate', 'gateway.certificate_failed', 'Publication failed.', ['stage' => 'publish']);
                    }

                    return ['changed' => true];
                }),
                $orbitHome,
            ),
            fpm: new NativeGatewayFpmConverger($processes, app(RemoteProgramCatalog::class), [...app()->tagged(GatewayServiceStartHook::class)]),
            builds: new NodeCaddyBuilder(
                app(NodeCaddyfileRenderer::class),
                new NodeCaddyBuildLock($orbitHome.'/locks/caddy-build'),
                new NodeCaddyTransport(new FakeAgentCommandRunner()->answer('caddy.publish', static function (array $input) use ($processes): array {
                    $result = $processes->run(new ProcessInvocation(
                        arguments: ['orbit-agent', 'command', 'caddy.publish'],
                        input: json_encode($input, JSON_THROW_ON_ERROR),
                    ));
                    if (! $result->succeeded()) {
                        preg_match('/orbit-caddy-build-stage=([a-z-]+)/', $result->stderr, $stage);
                        throw new AgentCommandRefused('caddy.publish', 'caddy.publication_failed', $result->stderr, ['stage' => $stage[1] ?? 'reload', 'message' => 'Error: aggregate route conflict']);
                    }

                    return ['result' => 'published'];
                })),
            ),
            caddyInstaller: new NativeGatewayCaddyInstaller(
                new FakeAgentCommandRunner()
                    ->answer('caddy.install-package', static function (array $input) use ($processes): array {
                        $result = $processes->run(new ProcessInvocation(['orbit-agent', 'command', 'caddy.install-package'], input: json_encode($input, JSON_THROW_ON_ERROR)));
                        if (! $result->succeeded()) {
                            throw new AgentCommandRefused('caddy.install-package', 'caddy.package_failed', $result->stderr, ['reason' => $result->stderr]);
                        }

                        return ['changed' => false];
                    })
                    ->answer('caddy.order-after-vpn', static function (array $input) use ($processes): array {
                        $processes->run(new ProcessInvocation(['orbit-agent', 'command', 'caddy.order-after-vpn'], input: json_encode($input, JSON_THROW_ON_ERROR)));

                        return ['changed' => false];
                    }),
            ),
            orbitHome: $orbitHome,
            checkoutPath: $checkoutPath,
            hibernator: $hibernator = new RecordingRuntimeHibernatorConverger,
            agentView: $agentView = new RecordingAgentViewConverger,
            releaseUnits: $releaseUnits = new RecordingGatewayReleaseUnitConverger,
        ),
        $processes,
        $issuer,
        $orbitHome,
        $hibernator,
        $agentView,
        $releaseUnits,
    ];
}

function gateway_web_node(): Node
{
    $node = Node::query()->firstOrCreate(['name' => 'gateway'], [
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.1',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $node->roles()->firstOrCreate(['role' => RoleName::Gateway], ['status' => LifecycleStatus::Active]);

    return $node;
}

function gateway_web_is_build(ProcessInvocation $invocation): bool
{
    return $invocation->arguments === ['orbit-agent', 'command', 'caddy.publish'];
}

function gateway_web_pushed(ProcessInvocation $invocation): string
{
    return json_decode((string) $invocation->input, true, flags: JSON_THROW_ON_ERROR)['content'];
}

final class RecordingGatewayReleaseUnitConverger implements GatewayReleaseUnitConverger
{
    public int $calls = 0;

    public function converge(): void
    {
        $this->calls++;
    }
}

final class RecordingRuntimeHibernatorConverger implements RuntimeHibernatorConverger
{
    public int $calls = 0;

    public function converge(): void
    {
        $this->calls++;
    }
}

final class RecordingAgentViewConverger implements AgentViewConverger
{
    public int $calls = 0;

    public function converge(): void
    {
        $this->calls++;
    }
}

final class GatewayFpmContentionProcessRunner implements ProcessRunner
{
    /** @var list<ProcessInvocation> */
    public array $calls = [];

    public ?NativeGatewayFpmConverger $contender = null;

    private bool $contentionStarted = false;

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $this->calls[] = $invocation;

        if (
            ! $this->contentionStarted
            && array_slice(array: $invocation->arguments, offset: 0, length: 4) === [
                'sudo',
                'php-fpm8.5',
                '--test',
                '--fpm-config',
            ]
        ) {
            $this->contentionStarted = true;
            $this->contender?->converge('/tmp/contending-gateway-pool.conf');
        }

        return new CommandResult(0, '', '', 2, false);
    }
}
