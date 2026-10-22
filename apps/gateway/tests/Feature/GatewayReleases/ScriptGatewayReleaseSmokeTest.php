<?php

declare(strict_types=1);

use App\Actions\GatewayReleases\DeployGatewayReleaseAction;
use App\Actions\GatewayReleases\SmokeGatewayReleaseAction;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Infrastructure\GatewayReleases\GitHubArtifactWebBuild;
use App\Infrastructure\GatewayReleases\ScriptGatewayReleaseSmoke;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/GatewaySmokeFixtures.php';

beforeEach(function (): void {
    $this->base = sys_get_temp_dir().'/orbit-smoke-'.Str::lower(Str::random(10));
    $this->layout = new GatewayReleaseLayout($this->base.'/orbit/apps/gateway');
    $this->id = 'abcdef012345';
    $this->sha = 'abcdef0123456789abcdef0123456789abcdef01';
    $this->release = smoke_release($this->base, $this->id);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->base));
});

describe('release smoke', function (): void {
    it('runs bin/gateway-smoke of the release against the stable checkout and returns its report', function (): void {
        $since = new DateTimeImmutable('2026-10-07T06:00:05.900+02:00');

        $result = smoke_runner($this->layout)->run($this->id, $this->sha, $since);

        expect($result['outcome'])->toBe('passed')
            ->and($result['report'])->toMatchArray(['schema' => 1, 'passed' => true, 'expected_sha' => $this->sha])
            ->and(file($this->release.'/smoke-args', FILE_IGNORE_NEW_LINES))->toBe([
                '--sha', $this->sha,
                '--timeout', '90',
                '--checkout', $this->base.'/orbit',
                '--web-dir', '/home/orbit/web',
                '--web-url', 'https://gateway.orbit/',
                '--up-url', 'https://gateway.orbit/up',
                '--status-url', 'https://gateway.orbit/api/v1/gateway/status',
                '--since', '2026-10-07T04:00:05Z',
            ]);
    });

    it('adds the document write check only when a smoke Project is configured', function (): void {
        smoke_runner($this->layout, project: 'gateway-smoke')->run($this->id, $this->sha);

        expect(array_slice(file($this->release.'/smoke-args', FILE_IGNORE_NEW_LINES), -3))->toBe(['--write-check', '--smoke-project', 'gateway-smoke']);
    });

    it('trusts the Orbit root CA for Python HTTPS calls', function (): void {
        $ca = $this->base.'/root-ca.pem';
        file_put_contents($ca, 'ca');
        putenv('SSL_CERT_FILE');

        smoke_runner($this->layout, caFile: $ca)->run($this->id, $this->sha);

        expect(file_get_contents($this->release.'/smoke-ssl'))->toBe($ca);
    });

    it('fails with the smoke report when a check fails', function (): void {
        file_put_contents($this->release.'/smoke-mode', 'fail');

        $exception = release_failure(fn () => smoke_runner($this->layout)->run($this->id, $this->sha));

        expect($exception->step)->toBe('smoke')
            ->and($exception->errorCode)->toBe('gateway.release_smoke_failed')
            ->and($exception->getMessage())->toContain('1 of 7 checks did not pass: web.')
            ->and($exception->phase['exit_code'])->toBe(1)
            ->and($exception->phase['report'])->toMatchArray(['passed' => false, 'failed_checks' => ['web']]);
    });

    it('fails when smoke prints no JSON result', function (): void {
        file_put_contents($this->release.'/smoke-mode', 'garbage');

        $exception = release_failure(fn () => smoke_runner($this->layout)->run($this->id, $this->sha));

        expect($exception->errorCode)->toBe('gateway.release_smoke_failed')
            ->and($exception->phase['stderr'])->toContain('Traceback');
    });

    it('tells a smoke run killed from elsewhere before its limit apart from a timeout', function (): void {
        file_put_contents($this->release.'/smoke-mode', 'killed');

        $exception = release_failure(fn () => smoke_runner($this->layout)->run($this->id, $this->sha));

        expect($exception->errorCode)->toBe('gateway.release_smoke_killed')
            ->and($exception->phase['exit_code'])->toBe(137);
    });

    it('passes the checks to skip to smoke', function (): void {
        smoke_runner($this->layout)->run($this->id, $this->sha, skip: ['web']);

        expect(array_slice(file($this->release.'/smoke-args', FILE_IGNORE_NEW_LINES), -2))->toBe(['--skip', 'web']);
    });

    it('fails when the release has no smoke command', function (): void {
        unlink($this->release.'/bin/gateway-smoke');

        expect(release_failure(fn () => smoke_runner($this->layout)->run($this->id, $this->sha))->errorCode)->toBe('gateway.release_smoke_missing');
    });
});

describe('web build and smoke from the container', function (): void {
    it('wires deploy and its prepare with the CI web build, and smoke with the configured limit and write check', function (): void {
        config([
            'orbit.gateway_checkout' => '/home/orbit/orbit/apps/gateway',
            'orbit.gateway_web' => '/home/orbit/web',
            'orbit.gateway_release_smoke_timeout' => 120,
            'orbit.gateway_release_smoke_project' => 'gateway-smoke',
        ]);
        $deploy = app(DeployGatewayReleaseAction::class);
        $promoter = new ReflectionProperty($deploy, 'promoter')->getValue($deploy);
        $builder = new ReflectionProperty($deploy, 'builder')->getValue($deploy);
        $smoke = new ReflectionProperty($promoter, 'smoke')->getValue($promoter);
        $web = new ReflectionProperty($promoter, 'web')->getValue($promoter);

        expect($web)->toBeInstanceOf(GitHubArtifactWebBuild::class)
            ->and(new ReflectionProperty($web, 'webRoot')->getValue($web))->toBe('/home/orbit/web')
            ->and(new ReflectionProperty($builder, 'web')->getValue($builder))->toBeInstanceOf(GitHubArtifactWebBuild::class)
            ->and($smoke)->toBeInstanceOf(ScriptGatewayReleaseSmoke::class)
            ->and(new ReflectionProperty($smoke, 'timeoutSeconds')->getValue($smoke))->toBe(120)
            ->and(new ReflectionProperty($smoke, 'writeCheckProject')->getValue($smoke))->toBe('gateway-smoke')
            ->and(app(SmokeGatewayReleaseAction::class))->toBeInstanceOf(SmokeGatewayReleaseAction::class);
    });
});
