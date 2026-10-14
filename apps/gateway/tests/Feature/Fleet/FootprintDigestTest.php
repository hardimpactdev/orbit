<?php

declare(strict_types=1);

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Fleet\FootprintArtifactSkipped;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Fleet\Footprint\CaddyFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\CaddyPackageFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\PrivateDnsFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\SandboxResidueFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\SourceDigest;
use App\Infrastructure\Fleet\Footprint\TmpfilesFootprintArtifact;
use App\Infrastructure\Nodes\CaddyPackageProgram;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;
use App\Models\NodeRole;
use Symfony\Component\Process\Process;
use Tests\Support\FakeNodeCaddyBuilds;
use Tests\Support\Fleet\FleetFixtures;
use Tests\Support\Fleet\FleetTestSsh;
use Tests\Support\Fleet\ScriptedSshExecutor;

describe('footprint digests', function (): void {
    it('pins the Caddy digest to the code the Caddy build renders from, never to a rendered Caddyfile', function (): void {
        $inputs = CaddyFootprintArtifact::inputs();
        $artifact = new CaddyFootprintArtifact(app(NodeCaddyfileRenderer::class), new FakeNodeCaddyBuilds);
        $node = FleetFixtures::node('dev', [RoleName::AppDev]);

        expect($inputs)->toContain(
            'app/Infrastructure/Caddy/Build/NodeCaddyfileRenderer.php',
            'app/Infrastructure/Caddy/Build/Sources/RouteCaddySiteSource.php',
            'app/Infrastructure/AppDev/DevelopmentSite.php',
            'app/Domain/Nodes/CaddyRelease.php',
        )
            ->and(array_filter($inputs, static fn (string $path): bool => str_starts_with($path, 'app/Models/')))->toBe([])
            ->and($artifact->digest($node))->toBe(SourceDigest::of($inputs))
            ->and($artifact->digest($node))->not->toBe(app(NodeCaddyfileRenderer::class)->render($node)->version);
    });

    it('keeps the private-DNS digest when a Node joins and the records change', function (): void {
        $vpn = FleetFixtures::node('vpn', [RoleName::Vpn]);
        $artifact = new PrivateDnsFootprintArtifact(new class implements PrivateDnsManager
        {
            public function converge(?Node $pendingNode = null): void {}
        });
        $before = $artifact->digest($vpn);

        FleetFixtures::node('joined', [RoleName::AppDev]);

        expect($artifact->digest($vpn))->toBe($before);
    });

    it('keeps the Caddy digest the same for every Node, whatever its sites', function (): void {
        $artifact = new CaddyFootprintArtifact(app(NodeCaddyfileRenderer::class), new FakeNodeCaddyBuilds);

        expect($artifact->digest(FleetFixtures::node('dev', [RoleName::AppDev])))
            ->toBe($artifact->digest(FleetFixtures::node('prod', [RoleName::AppProd])));
    });

    it('skips a Caddyfile the render or validation refuses with its reason, and fails a broken publish', function (): void {
        $node = FleetFixtures::node('dev', [RoleName::AppDev]);
        $builds = new FakeNodeCaddyBuilds;
        $artifact = new CaddyFootprintArtifact(app(NodeCaddyfileRenderer::class), $builds);

        foreach (['render' => 'caddy_render_refused', 'validate' => 'caddy_validate_refused'] as $stage => $reason) {
            $builds->failures['dev'] = new NodeCaddyBuildException('dev', $stage, 'A site is invalid.');
            expect(fn () => $artifact->apply($node))->toThrow(fn (FootprintArtifactSkipped $skipped) => expect($skipped->reason)->toBe($reason));
        }

        $builds->failures['dev'] = new NodeCaddyBuildException('dev', 'reload', 'Caddy did not reload.');
        expect(fn () => $artifact->apply($node))->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('node.footprint_caddy_failed'));
    });

    it('runs the Caddy package step on the Nodes that run Caddy, pinned to the program and the floor', function (): void {
        $ssh = new ScriptedSshExecutor;
        $caddy = new CaddyFootprintArtifact(app(NodeCaddyfileRenderer::class), new FakeNodeCaddyBuilds);
        $artifact = new CaddyPackageFootprintArtifact($caddy, FleetTestSsh::shell($ssh));
        $dev = FleetFixtures::node('dev', [RoleName::AppDev]);
        $database = FleetFixtures::node('database', [RoleName::Database]);

        expect($artifact->name())->toBe('caddy-package')
            ->and($artifact->applies($dev))->toBeTrue()
            ->and($artifact->applies($database))->toBe($caddy->applies($database))
            ->and($artifact->digest($dev))->toBe(SourceDigest::of([
                'app/Infrastructure/Nodes/CaddyPackageProgram.php',
                'app/Domain/Nodes/CaddyRelease.php',
            ]));

        $ssh->on('/'.preg_quote(CaddyPackageProgram::RELEASE_URL, '/').'/', new CommandResult(0, "orbit-caddy-package-result=unchanged\n", '', 1, false));
        expect($artifact->apply($dev))->toBeFalse()
            ->and($ssh->commands[0]->arguments)->toBe(['sudo', 'bash', '-seu', '--', ...CaddyPackageProgram::arguments()])
            ->and($ssh->commands[0]->input)->toBe(CaddyPackageProgram::render());
    });

    it('reports a changed Caddy package and fails a refused one with its own code', function (): void {
        $node = FleetFixtures::node('dev', [RoleName::AppDev]);
        $ssh = new ScriptedSshExecutor;
        $artifact = new CaddyPackageFootprintArtifact(
            new CaddyFootprintArtifact(app(NodeCaddyfileRenderer::class), new FakeNodeCaddyBuilds),
            FleetTestSsh::shell($ssh),
        );

        $ssh->on('/sudo bash/', new CommandResult(0, "orbit-caddy-package-result=changed\n", '', 1, false));
        expect($artifact->apply($node))->toBeTrue();

        $ssh = new ScriptedSshExecutor;
        $artifact = new CaddyPackageFootprintArtifact(
            new CaddyFootprintArtifact(app(NodeCaddyfileRenderer::class), new FakeNodeCaddyBuilds),
            FleetTestSsh::shell($ssh),
        );
        $ssh->on('/sudo bash/', new CommandResult(1, '', "The Caddy 2.11.7 package does not match the Orbit pin.\n", 1, false));
        expect(fn () => $artifact->apply($node))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('node.footprint_caddy_package_failed')
                ->and($exception->getMessage())->toContain('does not match the Orbit pin');
        });
    });

    it('publishes the tmpfiles rule on active Linux app-dev Nodes only', function (): void {
        $ssh = new ScriptedSshExecutor;
        $artifact = new TmpfilesFootprintArtifact(FleetTestSsh::shell($ssh));
        $dev = FleetFixtures::node('dev', [RoleName::AppDev]);
        $pending = FleetFixtures::node('pending');
        NodeRole::query()->create(['node_id' => $pending->id, 'role' => RoleName::AppDev, 'status' => LifecycleStatus::Provisioning]);

        expect($artifact->name())->toBe('tmpfiles')
            ->and($artifact->applies($dev))->toBeTrue()
            ->and($artifact->applies(FleetFixtures::node('database', [RoleName::Database])))->toBeFalse()
            ->and($artifact->applies(FleetFixtures::node('mac', [RoleName::AppDev], 'macos')))->toBeFalse()
            ->and($artifact->applies($pending->load('roles')))->toBeFalse()
            ->and($artifact->digest($dev))->toBe(hash('sha256', TmpfilesFootprintArtifact::Rule))
            ->and(TmpfilesFootprintArtifact::Rule)->toContain("\ne /tmp/orbit-* - - - 1d\ne /dev/shm/orbit-* - - - 1d\n");

        $ssh->on('/sudo bash/', new CommandResult(0, '', '', 1, false));
        expect($artifact->apply($dev))->toBeFalse()
            ->and($ssh->commands[0]->arguments)->toBe(['sudo', 'bash', '-seu', '--', '/etc/tmpfiles.d/orbit.conf', base64_encode(TmpfilesFootprintArtifact::Rule)]);

        $ssh = new ScriptedSshExecutor;
        $ssh->on('/sudo bash/', new CommandResult(0, "changed\n", '', 1, false));
        expect(new TmpfilesFootprintArtifact(FleetTestSsh::shell($ssh))->apply($dev))->toBeTrue();

        $ssh = new ScriptedSshExecutor;
        $ssh->on('/sudo bash/', new CommandResult(1, '', "mv: cannot move\n", 1, false));
        expect(fn () => new TmpfilesFootprintArtifact(FleetTestSsh::shell($ssh))->apply($dev))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('node.footprint_tmpfiles_failed')
                ->and($exception->getMessage())->toContain('cannot move');
        });
    });

    it('removes the retired sandbox host files on active Linux app-dev Nodes only', function (): void {
        $ssh = new ScriptedSshExecutor;
        $artifact = new SandboxResidueFootprintArtifact(FleetTestSsh::shell($ssh));
        $dev = FleetFixtures::node('dev', [RoleName::AppDev]);

        expect($artifact->name())->toBe('sandbox-residue')
            ->and($artifact->applies($dev))->toBeTrue()
            ->and($artifact->applies(FleetFixtures::node('database', [RoleName::Database])))->toBeFalse()
            ->and($artifact->applies(FleetFixtures::node('mac', [RoleName::AppDev], 'macos')))->toBeFalse()
            ->and($artifact->digest($dev))->toBe(hash('sha256', SandboxResidueFootprintArtifact::Script));

        $ssh->on('/sudo bash/', new CommandResult(0, '', '', 1, false));
        expect($artifact->apply($dev))->toBeFalse()
            ->and($ssh->commands[0]->arguments)->toBe(['sudo', 'bash', '-seu', '--', '/'])
            ->and($ssh->commands[0]->input)->toBe(SandboxResidueFootprintArtifact::Script);

        $ssh = new ScriptedSshExecutor;
        $ssh->on('/sudo bash/', new CommandResult(1, '', "rm: denied\n", 1, false));
        expect(fn () => new SandboxResidueFootprintArtifact(FleetTestSsh::shell($ssh))->apply($dev))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('node.footprint_sandbox_residue_failed')
                ->and($exception->getMessage())->toContain('rm: denied');
        });
    });

    it('drops the Incus dependency before it disables the boot service, and keeps unrelated files', function (): void {
        $root = sys_get_temp_dir().'/orbit-sandbox-residue-'.bin2hex(random_bytes(4));
        $files = [
            'etc/systemd/system/incus.service.d/orbit-sandbox-network.conf', 'etc/systemd/system/incus.service.d/other.conf',
            'etc/systemd/system/orbit-sandbox-host-network.service', 'etc/systemd/system/multi-user.target.wants/orbit-sandbox-host-network.service',
            'usr/local/libexec/orbit-sandbox-network', 'etc/orbit/sandbox-network.json', 'etc/orbit/agent/config.toml',
            'var/lib/orbit-sandbox-network/lock',
        ];
        foreach ($files as $file) {
            @mkdir(dirname($root.'/'.$file), 0755, true);
            file_put_contents($root.'/'.$file, 'x');
        }
        mkdir($root.'/bin');
        file_put_contents($root.'/bin/systemctl', "#!/bin/sh\nprintf '%s|%s\\n' \"\$*\" \"\$(ls \"\$ROOT/etc/systemd/system/incus.service.d\" 2>/dev/null | tr '\\n' ' ')\" >> \"\$ROOT/systemctl.log\"\n");
        chmod($root.'/bin/systemctl', 0755);
        $run = static function () use ($root): string {
            $process = new Process(['bash', '-seu', '--', $root.'/'], env: ['PATH' => $root.'/bin:'.getenv('PATH'), 'ROOT' => $root]);
            $process->setInput(SandboxResidueFootprintArtifact::Script)->mustRun();

            return $process->getOutput();
        };

        try {
            expect($run())->toBe("changed\n")
                ->and(file_get_contents($root.'/systemctl.log'))->toBe("daemon-reload|other.conf \ndisable orbit-sandbox-host-network.service|other.conf \ndaemon-reload|other.conf \n")
                ->and(file_exists($root.'/etc/systemd/system/incus.service.d/other.conf'))->toBeTrue()
                ->and(file_exists($root.'/etc/orbit/agent/config.toml'))->toBeTrue();
            foreach (['etc/systemd/system/incus.service.d/orbit-sandbox-network.conf', 'etc/systemd/system/orbit-sandbox-host-network.service',
                'etc/systemd/system/multi-user.target.wants/orbit-sandbox-host-network.service', 'usr/local/libexec/orbit-sandbox-network',
                'etc/orbit/sandbox-network.json', 'var/lib/orbit-sandbox-network'] as $gone) {
                expect(file_exists($root.'/'.$gone))->toBeFalse();
            }
            expect($run())->toBe('');
        } finally {
            (new Process(['rm', '-rf', '--', $root]))->run();
        }
    });
});
