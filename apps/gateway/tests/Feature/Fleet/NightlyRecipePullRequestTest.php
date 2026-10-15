<?php

declare(strict_types=1);

use App\Actions\Fleet\OpenNightlyRecipePullRequestAction;
use App\Actions\Fleet\OpenPhpBuildPullRequestAction;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Infrastructure\MachineSoftware\PhpBuildBump;
use App\Jobs\MaintainMachineRecipe;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\Fleet\FleetFixtures;
use Tests\Support\Fleet\RecordingReleaseAlertNotifier;

/** @return array<string, mixed> */
function nightlyRecipeFile(?string $snapshot = null): array
{
    $content = file_get_contents(resource_path('machine-software/recipe.json'));
    if ($snapshot !== null) {
        $content = str_replace('20261014T000000Z', $snapshot, $content);
    }

    return ['sha' => hash('sha1', $content), 'content' => base64_encode($content)];
}

function nightlyRecipeGitHub(array $file, ?array $branchFile = null, array $pulls = [], bool $denyWrite = false): void
{
    GitHubTestSupport::storeApp();
    config(['orbit.cli_releases.repository' => 'https://github.com/nckrtl/orbit']);
    Http::preventStrayRequests();
    $base = 'https://api.github.com/repos/nckrtl/orbit';
    Http::fake([
        $base.'/installation' => Http::response(['id' => 33]),
        'https://api.github.com/app/installations/33/access_tokens' => Http::response(['token' => 'ghs_nightly_test']),
        $base.'/git/ref/heads/main' => Http::response(['object' => ['sha' => str_repeat('a', 40)]]),
        $base.'/contents/apps/gateway/resources/machine-software/recipe.json*' => static function (Request $request) use ($file, $branchFile, $denyWrite) {
            if ($request->method() === 'PUT') {
                return $denyWrite ? Http::response(['message' => 'Refused'], 403) : Http::response(['commit' => ['sha' => str_repeat('b', 40)]]);
            }

            return Http::response(str_contains($request->url(), 'ref=nightly') ? ($branchFile ?? $file) : $file);
        },
        $base.'/git/refs' => Http::response(['ref' => 'refs/heads/nightly/recipe-20261015'], $branchFile === null ? 201 : 422),
        $base.'/pulls?*' => Http::response($pulls),
        $base.'/pulls' => Http::response(['html_url' => 'https://github.com/nckrtl/orbit/pull/99', 'number' => 99, 'user' => ['login' => 'orbit-bot']], 201),
    ]);
}

it('reads exactly main and opens a date-only PR through the existing App publisher', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-10-15 01:00:00 UTC'));
    $file = nightlyRecipeFile();
    nightlyRecipeGitHub($file);

    $url = app(OpenNightlyRecipePullRequestAction::class)->execute();

    expect($url)->toBe('https://github.com/nckrtl/orbit/pull/99');
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'PUT'
        && $request['branch'] === 'nightly/recipe-20261015'
        && $request['sha'] === $file['sha']
        && base64_decode($request['content']) === str_replace('20261014T000000Z', '20261015T000000Z', base64_decode($file['content']))
        && $request->hasHeader('Authorization', 'Bearer ghs_nightly_test'));
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/git/refs')
        && $request['sha'] === str_repeat('a', 40));
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/pulls')
        && $request['head'] === 'nightly/recipe-20261015' && $request['base'] === 'main');
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET' && str_contains($request->url(), '/pulls?')
        && $request['head'] === 'nckrtl:nightly/recipe-20261015');
});

it('does nothing when main already pins today or a newer snapshot', function (string $snapshot): void {
    $this->travelTo(new DateTimeImmutable('2026-10-15 01:00:00 UTC'));
    nightlyRecipeGitHub(nightlyRecipeFile($snapshot));

    expect(app(OpenNightlyRecipePullRequestAction::class)->execute())->toBeNull();

    Http::assertNotSent(static fn (Request $request): bool => in_array($request->method(), ['PUT', 'PATCH'], true)
        || str_ends_with($request->url(), '/git/refs') || str_contains($request->url(), '/pulls'));
})->with(['20261015T000000Z', '20261016T000000Z']);

it('reuses an existing date PR including a closed operator decision', function (string $state): void {
    $this->travelTo(new DateTimeImmutable('2026-10-15 01:00:00 UTC'));
    nightlyRecipeGitHub(nightlyRecipeFile(), pulls: [['html_url' => 'https://github.com/nckrtl/orbit/pull/99', 'number' => 99, 'state' => $state, 'merged_at' => null]]);

    expect(app(OpenNightlyRecipePullRequestAction::class)->execute())->toBe('https://github.com/nckrtl/orbit/pull/99');

    Http::assertNotSent(static fn (Request $request): bool => $request->method() === 'PUT' || str_ends_with($request->url(), '/git/refs'));
})->with(['open', 'closed']);

it('recovers after a crash between the date commit and opening its PR without another commit', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-10-15 01:00:00 UTC'));
    nightlyRecipeGitHub(nightlyRecipeFile(), nightlyRecipeFile('20261015T000000Z'));

    expect(app(OpenNightlyRecipePullRequestAction::class)->execute())->toBe('https://github.com/nckrtl/orbit/pull/99');

    Http::assertNotSent(static fn (Request $request): bool => $request->method() === 'PUT');
});

it('refuses to overwrite a concurrently edited date branch', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-10-15 01:00:00 UTC'));
    $changed = nightlyRecipeFile();
    $changed['sha'] = str_repeat('c', 40);
    nightlyRecipeGitHub(nightlyRecipeFile(), $changed);

    expect(fn () => app(OpenNightlyRecipePullRequestAction::class)->execute())->toThrow(RuntimeException::class, 'date branch recipe changed');

    Http::assertNotSent(static fn (Request $request): bool => $request->method() === 'PUT' || ($request->method() === 'POST' && str_ends_with($request->url(), '/pulls')));
});

it('fails before opening a PR when GitHub refuses the pin write', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-10-15 01:00:00 UTC'));
    nightlyRecipeGitHub(nightlyRecipeFile(), denyWrite: true);

    expect(fn () => app(OpenNightlyRecipePullRequestAction::class)->execute())->toThrow(RequestException::class);

    Http::assertNotSent(static fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/pulls'));
});

it('notifies through release alerts when the nightly job fails without including raw errors', function (): void {
    FleetFixtures::inPlace();
    FleetFixtures::adopt(FleetFixtures::Commit);
    $alerts = new RecordingReleaseAlertNotifier;
    app()->instance(ReleaseAlertNotifier::class, $alerts);

    new MaintainMachineRecipe(snapshot: true)->failed(new RuntimeException('private response'));

    expect($alerts->alerts)->toHaveCount(1);
    expect($alerts->alerts[0]->summary)->toContain('snapshot PR failed')->not->toContain('private response');
});

it('opens one reviewed PR that moves every Sury PHP pin together and reuses it on later nights', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-10-15 01:00:00 UTC'));
    $file = nightlyRecipeFile();
    $content = base64_decode($file['content']);
    $bumped = str_replace('8.5.11-1+0~20260924.26+ubuntu26.04~1.gbpbcb504', '8.5.12-1+0~20261014.1+ubuntu26.04~1.gbp000001', $content);
    $bumped = preg_replace_callback('/("url": "[^"]*8\.5\.12[^"]*",\s*"sha256": ")[0-9a-f]{64}/', static fn (array $m): string => $m[1].str_repeat('d', 64), $bumped);
    app()->instance(PhpBuildBump::class, new class($bumped) extends PhpBuildBump
    {
        public function __construct(private readonly string $bumped) {}

        public function bump(string $recipe): string
        {
            return $this->bumped;
        }
    });
    nightlyRecipeGitHub($file);

    $url = app(OpenPhpBuildPullRequestAction::class)->execute();

    expect($url)->toBe('https://github.com/nckrtl/orbit/pull/99');
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'PUT'
        && str_starts_with($request['branch'], OpenPhpBuildPullRequestAction::BranchPrefix)
        && base64_decode($request['content']) === $bumped);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/pulls')
        && str_starts_with($request['head'], 'nightly/php-build-') && preg_match('/\Anightly\/recipe-/', $request['head']) !== 1
        && $request['title'] === 'Bump the PHP build to PHP 8.5.12, PHP 8.4.26');
});

it('opens no PHP build PR when main pins the newest Sury build, and refuses a partial bump', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-10-15 01:00:00 UTC'));
    $file = nightlyRecipeFile();
    $content = base64_decode($file['content']);
    $partial = preg_replace('/"version": "8\.5\.11-1\+0~20260924\.26\+ubuntu26\.04~1\.gbpbcb504"/', '"version": "8.5.12-1"', $content, 1);
    foreach ([$content => null, $partial => InvalidArgumentException::class] as $result => $error) {
        app()->instance(PhpBuildBump::class, new class($result) extends PhpBuildBump
        {
            public function __construct(private readonly string $result) {}

            public function bump(string $recipe): string
            {
                return $this->result;
            }
        });
        nightlyRecipeGitHub($file);
        $open = fn () => app(OpenPhpBuildPullRequestAction::class)->execute();
        $error === null ? expect($open())->toBeNull() : expect($open)->toThrow($error);
    }

    Http::assertNotSent(static fn (Request $request): bool => in_array($request->method(), ['PUT', 'POST'], true) && str_contains($request->url(), '/repos/'));
});
