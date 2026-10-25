<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseLayout;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\GatewayReleaseFixture;
use Tests\Support\WebArtifactFixture;

beforeEach(function (): void {
    $this->web = new WebArtifactFixture(new GatewayReleaseLayout(sys_get_temp_dir().'/orbit-web-layout/orbit/apps/gateway'));
    $this->id = substr(WebArtifactFixture::Sha, 0, 12);
});

describe('web build install', function (): void {
    it('passes the verified private download path and digest to the native installer and cleans it', function (): void {
        $bytes = $this->web->github();
        $archive = null;
        $this->web->agents->answer('gateway.install-web-build', function (array $input) use ($bytes, &$archive): array {
            $archive = $input['archive'];
            expect($input)->toBe(['web_root' => $this->web->web, 'sha' => WebArtifactFixture::Sha, 'archive' => $archive, 'sha256' => hash('sha256', $bytes)])
                ->and(file_get_contents($archive))->toBe($bytes)
                ->and(fileperms($archive) & 0777)->toBe(0600);

            return ['reused' => false];
        });

        expect($this->web->build()->install($this->id, WebArtifactFixture::Sha))->toBeFalse()
            ->and($archive)->not->toBeNull()
            ->and(file_exists($archive))->toBeFalse()
            ->and($this->web->agents->commands())->toBe(['gateway.inspect-web-build', 'gateway.inspect-release-source', 'gateway.install-web-build']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.github.com/app/installations/7/access_tokens'
            && $request->data() === ['repositories' => ['orbit'], 'permissions' => ['actions' => 'read']]);
        Http::assertSent(fn (Request $request): bool => $request->url() === WebArtifactFixture::Storage && ! $request->hasHeader('Authorization'));
        Http::assertSentCount(6);
    });

    it('reuses a complete native install without calling GitHub', function (): void {
        $this->web->github();
        $this->web->agents->answer('gateway.inspect-web-build', ['installed' => true, 'archive_limit' => 4096]);

        expect($this->web->build()->install($this->id, WebArtifactFixture::Sha))->toBeTrue()
            ->and($this->web->agents->commands())->toBe(['gateway.inspect-web-build']);
        Http::assertNothingSent();
    });

    it('returns reuse reported by installation after the initial observation', function (): void {
        $this->web->github();
        $this->web->agents->answer('gateway.install-web-build', ['reused' => true]);
        expect($this->web->build()->install($this->id, WebArtifactFixture::Sha))->toBeTrue();
    });

    it('fails without a retry when CI published no artifact for the commit', function (array $variant, string $message): void {
        $this->web->github(...$variant);

        $exception = release_failure(fn () => $this->web->build()->install($this->id, WebArtifactFixture::Sha));

        expect($exception->errorCode)->toBe('gateway.release_web_build_missing')
            ->and($exception->step)->toBe('web')
            ->and($exception->status)->toBe(422)
            ->and($exception->getMessage())->toContain($message)
            ->and($this->web->agents->commands())->not->toContain('gateway.install-web-build');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === WebArtifactFixture::Storage);
    })->with([
        'no artifact' => [['missing' => true], 'published no artifact'],
        'expired' => [['artifact' => ['expired' => true]], 'has expired'],
        'pull request run' => [['run' => ['event' => 'pull_request']], 'published no artifact'],
        'fork run' => [['run' => ['head_repository' => ['id' => 99, 'full_name' => 'fork/orbit']]], 'published no artifact'],
        'other branch' => [['run' => ['head_branch' => 'feature']], 'published no artifact'],
        'other workflow' => [['run' => ['path' => '.github/workflows/dependency-audit.yml']], 'published no artifact'],
        'other commit' => [['artifact' => ['workflow_run' => ['id' => 37647307459, 'repository_id' => 1348221080, 'head_repository_id' => 1348221080, 'head_branch' => 'main', 'head_sha' => str_repeat('b', 40)]]], 'published no artifact'],
    ]);

    it('accepts the artifact of a manual CI dispatch on main', function (): void {
        $this->web->github(run: ['event' => 'workflow_dispatch']);

        expect($this->web->build()->install($this->id, WebArtifactFixture::Sha))->toBeFalse();
    });

    it('refuses an artifact without a digest before it downloads it', function (): void {
        $this->web->github(artifact: ['digest' => null]);

        expect(release_failure(fn () => $this->web->build()->install($this->id, WebArtifactFixture::Sha))->errorCode)->toBe('gateway.release_web_build_invalid')
            ->and($this->web->agents->commands())->not->toContain('gateway.install-web-build');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === WebArtifactFixture::Storage);
    });

    it('retries later when GitHub cannot be read and never shows the token', function (): void {
        $this->web->github(denied: true);

        $exception = release_failure(fn () => $this->web->build()->install($this->id, WebArtifactFixture::Sha));

        expect($exception->errorCode)->toBe('gateway.release_web_build_unavailable')
            ->and($exception->getMessage())->not->toContain(WebArtifactFixture::Token)
            ->and($exception->getPrevious())->toBeNull()
            ->and($this->web->agents->commands())->not->toContain('gateway.install-web-build');
    });

    it('refuses an archive that does not match the artifact digest', function (): void {
        $this->web->github(artifact: ['digest' => 'sha256:'.str_repeat('0', 64)]);

        expect(release_failure(fn () => $this->web->build()->install($this->id, WebArtifactFixture::Sha))->errorCode)->toBe('gateway.release_web_build_invalid')
            ->and($this->web->agents->commands())->not->toContain('gateway.install-web-build');
    });

    it('refuses an artifact above the size limit before downloading it', function (): void {
        $this->web->github(artifact: ['size_in_bytes' => 4097]);

        expect(release_failure(fn () => $this->web->build()->install($this->id, WebArtifactFixture::Sha))->errorCode)->toBe('gateway.release_web_build_invalid');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === WebArtifactFixture::Storage);
    });

    it('fails before a download when the web directory is missing', function (): void {
        $this->web->github();
        $this->web->agents->refuse('gateway.inspect-web-build', 'gateway.release_web_directory_missing');

        expect(release_failure(fn () => $this->web->build()->install($this->id, WebArtifactFixture::Sha))->errorCode)->toBe('gateway.release_web_directory_missing');
        Http::assertNothingSent();
    });
});

describe('web command orchestration', function (): void {
    it('restores the exact target returned by publication once', function (?string $previous): void {
        $this->web->agents->answer('gateway.publish-web-build', ['previous' => $previous]);
        $build = $this->web->build();
        $build->publish('bbbbbbbbbbbb');
        $build->restore('aaaaaaaaaaaa');
        $build->restore('aaaaaaaaaaaa');

        expect($this->web->agents->commands())->toBe(['gateway.publish-web-build', 'gateway.restore-web-build'])
            ->and($this->web->agents->calls[1]['input'])->toBe(['web_root' => $this->web->web, 'release' => 'aaaaaaaaaaaa', 'previous' => $previous]);
    })->with([null, 'releases/cccccccccccc', '/srv/orbit/web/releases/cccccccccccc']);

    it('does not restore if this attempt never published', function (): void {
        $this->web->build()->restore('aaaaaaaaaaaa');
        expect($this->web->agents->calls)->toBe([]);
    });

    it('keeps restoration intent when the native restore refuses', function (): void {
        $build = $this->web->build();
        $build->publish('bbbbbbbbbbbb');
        $this->web->agents->refuse('gateway.restore-web-build', 'gateway.release_web_publish_failed');
        expect(release_failure(fn () => $build->restore('aaaaaaaaaaaa'))->status)->toBe(500);
        $this->web->agents->answer('gateway.restore-web-build', ['changed' => true]);
        $build->restore('aaaaaaaaaaaa');
        expect($this->web->agents->commands())->toBe(['gateway.publish-web-build', 'gateway.restore-web-build', 'gateway.restore-web-build']);
    });

    it('does not restore after failed publication', function (): void {
        $build = $this->web->build();
        $this->web->agents->refuse('gateway.publish-web-build', 'gateway.release_web_build_missing');
        expect(release_failure(fn () => $build->publish('bbbbbbbbbbbb'))->status)->toBe(422);
        $build->restore('aaaaaaaaaaaa');
        expect($this->web->agents->commands())->toBe(['gateway.publish-web-build']);
    });

    it('sends removal and retention intent without guessing which build is current', function (): void {
        $build = $this->web->build();
        $build->remove('aaaaaaaaaaaa');
        $build->prune([]);
        $build->prune(['bbbbbbbbbbbb']);
        expect($this->web->agents->commands())->toBe(['gateway.remove-web-build', 'gateway.prune-web-builds'])
            ->and($this->web->agents->calls[0]['input'])->toBe(['web_root' => $this->web->web, 'release' => 'aaaaaaaaaaaa'])
            ->and($this->web->agents->calls[1]['input'])->toBe(['web_root' => $this->web->web, 'retained' => ['bbbbbbbbbbbb']]);
    });

    it('maps native installation refusals and cleans the provider download', function (string $code, int $status): void {
        $this->web->github();
        $this->web->agents->refuse('gateway.install-web-build', $code);
        $error = release_failure(fn () => $this->web->build()->install($this->id, WebArtifactFixture::Sha));
        $input = collect($this->web->agents->calls)->firstWhere('command', 'gateway.install-web-build')['input'];
        expect($error->errorCode)->toBe($code)->and($error->status)->toBe($status)->and($error->step)->toBe('web')
            ->and(file_exists($input['archive']))->toBeFalse();
    })->with([
        ['gateway.release_web_build_invalid', 422],
        ['gateway.release_web_directory_missing', 500],
        ['gateway.release_web_install_failed', 500],
    ]);

    it('refuses malformed native answers', function (string $command, string $method, array $args, array $answer): void {
        $this->web->github();
        $this->web->agents->answer($command, $answer);
        $build = $this->web->build();
        if ($method === 'restore') {
            $build->publish('aaaaaaaaaaaa');
        }
        expect(release_failure(fn () => $build->$method(...$args))->status)->toBe(500);
    })->with([
        ['gateway.inspect-web-build', 'install', [substr(WebArtifactFixture::Sha, 0, 12), WebArtifactFixture::Sha], ['installed' => false, 'archive_limit' => 0]],
        ['gateway.install-web-build', 'install', [substr(WebArtifactFixture::Sha, 0, 12), WebArtifactFixture::Sha], ['reused' => 'yes']],
        ['gateway.publish-web-build', 'publish', ['aaaaaaaaaaaa'], []],
        ['gateway.restore-web-build', 'restore', ['aaaaaaaaaaaa'], ['changed' => null]],
        ['gateway.remove-web-build', 'remove', ['aaaaaaaaaaaa'], []],
        ['gateway.prune-web-builds', 'prune', [['aaaaaaaaaaaa']], []],
    ]);
});

describe('prepare with the web build', function (): void {
    beforeEach(function (): void {
        $this->fixture = GatewayReleaseFixture::create();
        $this->fixture->inPlace();
        $this->web = new WebArtifactFixture($this->fixture->layout, $this->fixture->base.'/web');
    });

    afterEach(function (): void {
        $this->fixture->cleanup();
    });

    it('installs the web build of the commit while it prepares the release', function (): void {
        $sha = $this->fixture->commit('Release with a web build');
        $this->web->github(sha: $sha);

        $release = $this->fixture->builder($this->web->build())->prepare($sha);

        expect($this->fixture->layout->preparedCommit($release->id))->toBe($sha)
            ->and(collect($this->web->agents->calls)->firstWhere('command', 'gateway.install-web-build')['input']['sha'])->toBe($sha)
            ->and($this->web->agents->commands())->not->toContain('gateway.publish-web-build');
    });

    it('fails prepare before anything is live when the artifact is missing', function (): void {
        $sha = $this->fixture->commit('Release without a web build');
        $this->web->github(sha: $sha, missing: true);

        $exception = release_failure(fn () => $this->fixture->builder($this->web->build())->prepare($sha));

        expect($exception->errorCode)->toBe('gateway.release_web_build_missing')
            ->and($exception->sha)->toBe($sha)
            ->and($this->fixture->layout->preparedCommit(substr($sha, 0, 12)))->toBeNull()
            ->and($this->fixture->agents->commands())->not->toContain('gateway.finish-release', 'gateway.switch-release')
            ->and($this->web->agents->commands())->not->toContain('gateway.install-web-build');
    });

    it('installs a missing web build again when it reuses a prepared release', function (): void {
        $sha = $this->fixture->commit('Reused release');
        $this->web->github(sha: $sha);
        $release = $this->fixture->builder($this->web->build())->prepare($sha);
        $this->web->agents->calls = [];

        $reused = $this->fixture->builder($this->web->build())->prepare($sha);

        expect($reused->reused)->toBeTrue()
            ->and($this->web->agents->commands())->toContain('gateway.install-web-build');
    });
});
