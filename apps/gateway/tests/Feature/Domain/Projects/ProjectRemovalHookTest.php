<?php

declare(strict_types=1);

use App\Actions\Projects\DestroyProjectAction;
use App\Domain\Projects\ProjectRemovalHook;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Project;

final class RecordingProjectRemovalHook implements ProjectRemovalHook
{
    /** @var list<string> */
    public static array $calls = [];

    public static bool $refuse = false;

    public function lock(Project $project): void
    {
        self::$calls[] = "lock:{$project->slug}:".(Project::query()->whereKey($project->id)->exists() ? 'present' : 'gone');
    }

    public function remove(Project $project): void
    {
        self::$calls[] = "remove:{$project->slug}";
        if (self::$refuse) {
            throw new ResourceOperationException('test.refused', 'The test hook refused the removal.', 409);
        }
    }
}

beforeEach(function (): void {
    RecordingProjectRemovalHook::$calls = [];
    RecordingProjectRemovalHook::$refuse = false;
    $this->app->tag([RecordingProjectRemovalHook::class], ProjectRemovalHook::class);
    $this->removedProject = Project::query()->create(['name' => 'Hooked', 'slug' => 'hooked', 'repository_url' => 'https://example.test/hooked.git']);
});

it('locks through every contributed hook first, then removes through it before the Project row goes', function (): void {
    app(DestroyProjectAction::class)->execute($this->removedProject);

    expect(RecordingProjectRemovalHook::$calls)->toBe(['lock:hooked:present', 'remove:hooked'])
        ->and(Project::query()->whereKey($this->removedProject->id)->exists())->toBeFalse();
});

it('keeps the Project when a contributed hook refuses the removal', function (): void {
    RecordingProjectRemovalHook::$refuse = true;

    expect(fn () => app(DestroyProjectAction::class)->execute($this->removedProject))
        ->toThrow(ResourceOperationException::class, 'The test hook refused the removal.')
        ->and(Project::query()->whereKey($this->removedProject->id)->exists())->toBeTrue();
});
