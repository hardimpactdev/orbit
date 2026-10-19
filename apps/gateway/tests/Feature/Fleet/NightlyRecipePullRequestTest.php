<?php

declare(strict_types=1);

use App\Actions\Fleet\OpenNightlyRecipePullRequestAction;
use App\Actions\Fleet\OpenPhpBuildPullRequestAction;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Infrastructure\MachineSoftware\PhpBuildBump;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\MachineSoftware\SnapshotAvailability;
use App\Jobs\MaintainMachineRecipe;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\Fleet\FakeUbuntuSnapshot;
use Tests\Support\Fleet\FleetFixtures;
use Tests\Support\Fleet\RecordingReleaseAlertNotifier;

/** @return array<string, mixed> */
function nightlyRecipeFile(?string $snapshot = null): array
{
    $content = file_get_contents(resource_path('machine-software/recipe.json'));
    if ($snapshot !== null) {
        $content = str_replace(new RecipeLoader()->load()->snapshot, $snapshot, $content);
    }

    return ['sha' => hash('sha1', $content), 'content' => base64_encode($content)];
}

function nightlyRecipeDate(int $days = 1): DateTimeImmutable
{
    return new DateTimeImmutable(new RecipeLoader()->load()->snapshot)->modify("+{$days} days");
}

function nightlyRecipeGitHub(array $file, ?array $branchFile = null, array $pulls = [], bool $denyWrite = false): void
{
    app()->instance(SnapshotAvailability::class, new FakeUbuntuSnapshot);
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
        $base.'/git/refs' => Http::response(['ref' => 'refs/heads/nightly/recipe-'.nightlyRecipeDate()->format('Ymd')], $branchFile === null ? 201 : 422),
        $base.'/pulls?*' => static fn (Request $request) => Http::response(isset($request['head']) ? $pulls : []),
        $base.'/pulls/99' => Http::response($pulls[0] ?? []),
        $base.'/pulls' => Http::response(['html_url' => 'https://github.com/nckrtl/orbit/pull/99', 'number' => 99, 'user' => ['login' => 'orbit-bot']], 201),
    ]);
}

it('reads exactly main and opens a snapshot PR through the existing App publisher', function (): void {
    $this->travelTo(nightlyRecipeDate()->setTime(1, 0));
    $file = nightlyRecipeFile();
    $branch = 'nightly/recipe-'.nightlyRecipeDate()->format('Ymd');
    $expected = base64_decode(nightlyRecipeFile(nightlyRecipeDate()->format('Ymd\THis\Z'))['content']);
    nightlyRecipeGitHub($file);

    $url = app(OpenNightlyRecipePullRequestAction::class)->execute();

    expect($url)->toBe('https://github.com/nckrtl/orbit/pull/99');
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'PUT'
        && $request['branch'] === $branch
        && $request['sha'] === $file['sha']
        && base64_decode($request['content']) === $expected
        && $request->hasHeader('Authorization', 'Bearer ghs_nightly_test'));
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/git/refs')
        && $request['sha'] === str_repeat('a', 40));
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/pulls')
        && $request['head'] === $branch && $request['base'] === 'main');
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET' && str_contains($request->url(), '/pulls?')
        && ($request['head'] ?? null) === 'nckrtl:'.$branch);
});

it('does nothing when main already pins today or a newer snapshot', function (int $days): void {
    $this->travelTo(nightlyRecipeDate()->setTime(1, 0));
    nightlyRecipeGitHub(nightlyRecipeFile(nightlyRecipeDate($days)->format('Ymd\THis\Z')));

    expect(app(OpenNightlyRecipePullRequestAction::class)->execute())->toBeNull();

    Http::assertNotSent(static fn (Request $request): bool => in_array($request->method(), ['PUT', 'PATCH'], true)
        || str_ends_with($request->url(), '/git/refs') || (str_contains($request->url(), '/pulls') && isset($request['head'])));
})->with(['today' => 1, 'newer' => 2]);

it('reuses an existing date PR including a closed operator decision', function (string $state): void {
    $this->travelTo(nightlyRecipeDate()->setTime(1, 0));
    nightlyRecipeGitHub(nightlyRecipeFile(), pulls: [['html_url' => 'https://github.com/nckrtl/orbit/pull/99', 'number' => 99, 'state' => $state, 'merged_at' => null]]);

    expect(app(OpenNightlyRecipePullRequestAction::class)->execute())->toBe('https://github.com/nckrtl/orbit/pull/99');

    Http::assertNotSent(static fn (Request $request): bool => $request->method() === 'PUT' || str_ends_with($request->url(), '/git/refs'));
})->with(['open', 'closed']);

it('recovers after a crash between the date commit and opening its PR without another commit', function (): void {
    $this->travelTo(nightlyRecipeDate()->setTime(1, 0));
    nightlyRecipeGitHub(nightlyRecipeFile(), nightlyRecipeFile(nightlyRecipeDate()->format('Ymd\THis\Z')));

    expect(app(OpenNightlyRecipePullRequestAction::class)->execute())->toBe('https://github.com/nckrtl/orbit/pull/99');

    Http::assertNotSent(static fn (Request $request): bool => $request->method() === 'PUT');
});

it('refuses to overwrite a concurrently edited date branch', function (): void {
    $this->travelTo(nightlyRecipeDate()->setTime(1, 0));
    $changed = nightlyRecipeFile();
    $changed['sha'] = str_repeat('c', 40);
    nightlyRecipeGitHub(nightlyRecipeFile(), $changed);

    expect(fn () => app(OpenNightlyRecipePullRequestAction::class)->execute())->toThrow(RuntimeException::class, 'date branch recipe changed');

    Http::assertNotSent(static fn (Request $request): bool => $request->method() === 'PUT' || ($request->method() === 'POST' && str_ends_with($request->url(), '/pulls')));
});

it('fails before opening a PR when GitHub refuses the pin write', function (): void {
    $this->travelTo(nightlyRecipeDate()->setTime(1, 0));
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
    $this->travelTo(nightlyRecipeDate()->setTime(1, 0));
    $file = nightlyRecipeFile();
    $content = base64_decode($file['content']);
    $recipe = new RecipeLoader()->load();
    $current = $recipe->php($recipe->ciPhp)->phpVersion;
    $next = preg_replace_callback('/[0-9]+$/', static fn (array $match): string => (string) ((int) $match[0] + 1), $current);
    $document = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
    foreach ($document['tools'] as &$tool) {
        if ($tool['source'] !== 'artifact' || ! str_starts_with($tool['version'], $current.'-')) {
            continue;
        }
        $tool['version'] = str_replace($current.'-', $next.'-', $tool['version']);
        foreach ($tool['artifacts'] as &$artifact) {
            $artifact['url'] = str_replace($current.'-', $next.'-', $artifact['url']);
            $artifact['sha256'] = str_repeat('d', 64);
        }
        unset($artifact);
    }
    unset($tool);
    $bumped = json_encode($document, JSON_THROW_ON_ERROR);
    $releases = [];
    foreach ($recipe->php as $version => $build) {
        $releases[] = 'PHP '.($version === $recipe->ciPhp ? $next : $build->phpVersion);
    }
    $title = 'Bump the PHP build to '.implode(', ', $releases);
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
        && $request['title'] === $title);
});

it('opens no PHP build PR when main pins the newest Sury build, and refuses a partial bump', function (): void {
    $this->travelTo(nightlyRecipeDate()->setTime(1, 0));
    $file = nightlyRecipeFile();
    $content = base64_decode($file['content']);
    $recipe = new RecipeLoader()->load();
    $document = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
    $pin = &$document['tools']["php{$recipe->ciPhp}-cli"];
    $current = $pin['version'];
    $pin['version'] = $current.'.partial';
    foreach ($pin['artifacts'] as &$artifact) {
        $artifact['url'] = str_replace($current, $pin['version'], $artifact['url']);
    }
    unset($artifact, $pin);
    $partial = json_encode($document, JSON_THROW_ON_ERROR);
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

it('waits without alerting for main recipe definitions and catches up on the same date only once', function (): void {
    $this->travelTo(nightlyRecipeDate()->setTime(1, 0));
    FleetFixtures::inPlace();
    FleetFixtures::adopt(FleetFixtures::Commit);
    $alerts = new RecordingReleaseAlertNotifier;
    app()->instance(ReleaseAlertNotifier::class, $alerts);
    $bump = new class extends PhpBuildBump
    {
        public int $calls = 0;

        public function __construct() {}

        public function bump(string $recipe): string
        {
            $this->calls++;

            return $recipe;
        }
    };
    app()->instance(PhpBuildBump::class, $bump);
    $ahead = nightlyRecipeFile();
    $document = json_decode(base64_decode($ahead['content']), true, flags: JSON_THROW_ON_ERROR);
    $document['tools']['not-yet-deployed'] = ['source' => 'ubuntu', 'package' => 'future', 'version' => '1'];
    $ahead['content'] = base64_encode(json_encode($document, JSON_THROW_ON_ERROR));
    nightlyRecipeGitHub($ahead);

    app()->call([new MaintainMachineRecipe(snapshot: true), 'handle']);

    expect($bump->calls)->toBe(0);
    expect($alerts->alerts)->toBe([]);
    Http::assertNotSent(static fn (Request $request): bool => $request->method() !== 'GET' && str_contains($request->url(), '/repos/'));
    $this->travel(5)->minutes();
    Http::swap(new Factory);
    nightlyRecipeGitHub(nightlyRecipeFile());

    app()->call([new MaintainMachineRecipe(snapshot: true), 'handle']);

    expect($bump->calls)->toBe(1);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'PUT');
    $sent = Http::recorded()->count();
    app()->call([new MaintainMachineRecipe(snapshot: true), 'handle']);
    expect(Http::recorded()->count())->toBe($sent);
});

it('bumps every Ubuntu pin with the snapshot while preserving all artifact and profile definitions', function (): void {
    $this->travelTo(nightlyRecipeDate()->setTime(1, 0));
    $file = nightlyRecipeFile();
    nightlyRecipeGitHub($file);
    app()->instance(SnapshotAvailability::class, new FakeUbuntuSnapshot('+snapshot1'));

    app(OpenNightlyRecipePullRequestAction::class)->execute();

    $request = Http::recorded(fn (Request $request): bool => $request->method() === 'PUT')->sole()[0];
    $before = json_decode(base64_decode($file['content']), true, flags: JSON_THROW_ON_ERROR);
    $after = json_decode(base64_decode($request['content']), true, flags: JSON_THROW_ON_ERROR);
    expect($after['ubuntu']['snapshot'])->toBe(nightlyRecipeDate()->format('Ymd\THis\Z'));
    foreach ($before['tools'] as $name => $tool) {
        if ($tool['source'] === 'ubuntu') {
            expect($after['tools'][$name]['version'])->toBe($tool['version'].'+snapshot1');
            $after['tools'][$name]['version'] = $tool['version'];
        }
    }
    $after['ubuntu']['snapshot'] = $before['ubuntu']['snapshot'];
    expect($after)->toBe($before);
});
