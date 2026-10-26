<?php

declare(strict_types=1);

use App\Actions\Gateway\ShowGatewayStatusAction;
use App\Data\Fleet\DesiredCliReleaseData;
use App\Domain\Fleet\CliReleaseUnavailableReason;
use App\Domain\Fleet\FleetServingRelease;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentDelivery;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeAgentCommandRunner;

beforeEach(function (): void {
    $this->base = sys_get_temp_dir().'/orbit-release-inventory-'.bin2hex(random_bytes(6));
    mkdir($this->base.'/releases', 0755, true);
    $this->layout = new GatewayReleaseLayout($this->base.'/orbit/apps/gateway');
    $this->release = function (string $sha, ?int $modified = null): string {
        $id = substr($sha, 0, 12);
        mkdir($this->layout->releaseApplicationPath($id), 0755, true);
        file_put_contents($this->layout->releasePath($id).'/REVISION', $sha."\n");
        touch($this->layout->releasePath($id).'/REVISION', $modified ?? time());

        return $id;
    };
    // No agent answers: the inventory must never need one.
    app()->instance(AgentCommandRunner::class, new FakeAgentCommandRunner);
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->base);
});

it('reads the current release, prepared commits, and incomplete releases from the layout without the agent', function (): void {
    $old = ($this->release)(str_repeat('a', 40), 1000);
    $new = ($this->release)(str_repeat('b', 40), 2000);
    mkdir($this->layout->releasePath('cccccccccccc'));
    touch($this->layout->releasePath('cccccccccccc'), 1500);
    mkdir($this->layout->releasePath('dddddddddddd'));
    file_put_contents($this->layout->releasePath('dddddddddddd').'/REVISION', 'not a commit');
    symlink($this->layout->linkTarget($old), $this->layout->currentPath());

    $inventory = $this->layout->observe();

    expect($inventory->current)->toBe($old)->and($inventory->state)->toBe('adopted')->and($inventory->superseded)->toBeFalse()
        ->and($inventory->commits)->toBe([$old => str_repeat('a', 40), $new => str_repeat('b', 40)])
        ->and($inventory->incomplete)->toBe(['cccccccccccc' => 1500])
        ->and($inventory->retained)->toBe([$new, $old])
        ->and($this->layout->preparedCommit('dddddddddddd'))->toBeNull()
        ->and(app(AgentCommandRunner::class)->calls)->toBe([]);
});

it('reports an in-place checkout and an unreadable link without a current release', function (): void {
    mkdir($this->layout->applicationPath, 0755, true);
    expect($this->layout->observe())->state->toBe('in_place')->current->toBeNull();

    new Filesystem()->deleteDirectory($this->layout->currentPath());
    symlink('releases/eeeeeeeeeeee', $this->layout->currentPath());
    expect($this->layout->observe())->state->toBe('unreadable')->current->toBe('eeeeeeeeeeee')
        ->and($this->layout->isAdopted())->toBeTrue();
});

it('reports whether the serving release supersedes the running application', function (): void {
    $serving = ($this->release)(str_repeat('a', 40));
    $running = ($this->release)(str_repeat('b', 40));
    symlink($this->layout->linkTarget($serving), $this->layout->currentPath());
    config(['orbit.gateway_checkout' => $this->layout->applicationPath]);

    expect(new FleetServingRelease($this->layout->releaseApplicationPath($running))->supersedes())->toBeTrue()
        ->and(new FleetServingRelease($this->layout->releaseApplicationPath($serving))->supersedes())->toBeFalse()
        ->and(new FleetServingRelease($this->layout->releaseApplicationPath($serving))->read())->toBe(['state' => 'adopted', 'commit' => str_repeat('a', 40)]);
});

it('refuses a releases directory that is not a directory', function (): void {
    rmdir($this->base.'/releases');
    file_put_contents($this->base.'/releases', '');

    expect(fn () => $this->layout->observe())->toThrow(GatewayReleaseException::class, 'cannot be read');
});

it('answers status for a release whose agent manifest is missing', function (): void {
    $id = ($this->release)(str_repeat('a', 40));
    symlink($this->layout->linkTarget($id), $this->layout->currentPath());
    config(['orbit.gateway_checkout' => $this->layout->applicationPath]);
    app()->instance(AgentDelivery::class, new AgentDelivery($this->base.'/missing/manifest.json', $this->base.'/artifacts'));
    // A desired state cached for this version still needs the agent manifest to be shown.
    config(['app.version' => str_repeat('a', 40)]);
    Cache::put('orbit:desired-fleet-state:v1:'.hash('sha256', str_repeat('a', 40)), [
        'commit' => str_repeat('a', 40), 'cli' => DesiredCliReleaseData::unavailable(CliReleaseUnavailableReason::GitHubUnavailable)->toArray(),
    ], 60);

    $status = app(ShowGatewayStatusAction::class)->handle(authenticated: true);

    expect($status->status)->toBe('ok')->and($status->release)->toBe($id)->and($status->releaseSha)->toBe(str_repeat('a', 40))
        ->and($status->desiredFleetState)->toBeNull();
});
