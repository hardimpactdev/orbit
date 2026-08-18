<?php

declare(strict_types=1);

use App\Services\Skill\SkillInstallActions;
use App\Services\Skill\SkillInstallFailure;
use App\Services\Skill\SkillInstallPlan;
use App\Services\Skill\SkillInstallRequest;
use App\Services\Skill\SkillInstallResult;
use App\Services\Skill\SkillTargetResolver;
use App\Services\Updates\CheckoutPathResolver;
use Illuminate\Support\Facades\File;

final readonly class CountingSkillTargetResolver extends SkillTargetResolver
{
    /**
     * @param  object{resolveCalls: int}  $counter
     */
    public function __construct(
        public object $counter,
        ?string $homeOverride = null,
    ) {
        parent::__construct($homeOverride);
    }

    /**
     * @return array{provider: ?string, target: string}|SkillInstallFailure
     */
    public function resolve(?string $providerSlug, ?string $path): array|SkillInstallFailure
    {
        $this->counter->resolveCalls++;

        return parent::resolve($providerSlug, $path);
    }
}

beforeEach(function (): void {
    $this->tempRoot = sys_get_temp_dir().'/orbit-skill-install-actions-'.bin2hex(random_bytes(4));
    $this->tempHome = $this->tempRoot.'/home';
    $this->sourceSkillPath = $this->tempRoot.'/source/.agents/skills/orbit';

    File::deleteDirectory($this->tempRoot);
    File::ensureDirectoryExists($this->sourceSkillPath);
    file_put_contents(filename: $this->sourceSkillPath.'/SKILL.md', data: "# Orbit skill\n");

    $this->resolveCounter = (object) ['resolveCalls' => 0];
    $this->resolver = new CountingSkillTargetResolver(
        counter: $this->resolveCounter,
        homeOverride: $this->tempHome,
    );
    $this->actions = new SkillInstallActions(
        checkoutPaths: new CheckoutPathResolver,
        targetResolver: $this->resolver,
        sourceOverride: $this->sourceSkillPath,
    );
});

afterEach(function (): void {
    File::deleteDirectory($this->tempRoot);
});

function skill_install_request(bool $force = false): SkillInstallRequest
{
    return new SkillInstallRequest(provider: 'codex', path: null, force: $force);
}

function skill_actions_target(string $home): string
{
    return rtrim(string: $home, characters: '/').'/.agents/skills/orbit';
}

describe('SkillInstallActions plan and install', function (): void {
    it('resolves the target exactly once across plan() then install()', function (): void {
        $plan = $this->actions->plan(skill_install_request());

        expect($plan)->toBeInstanceOf(SkillInstallPlan::class);

        $result = $this->actions->install($plan);

        expect($result)
            ->toBeInstanceOf(SkillInstallResult::class)
            ->and($this->resolveCounter->resolveCalls)
            ->toBe(1)
            ->and(is_file($plan->target.'/SKILL.md'))
            ->toBeTrue();
    });

    it('fails with missing_source when the source disappears after plan()', function (): void {
        $plan = $this->actions->plan(skill_install_request());

        expect($plan)->toBeInstanceOf(SkillInstallPlan::class);

        File::deleteDirectory($this->sourceSkillPath);

        $result = $this->actions->install($plan);

        expect($result)
            ->toBeInstanceOf(SkillInstallFailure::class)
            ->and($result->code)
            ->toBe('validation_failed')
            ->and($result->meta['reason'])
            ->toBe('missing_source')
            ->and($result->meta['field'])
            ->toBe('source')
            ->and($result->meta['source'])
            ->toBe($plan->source)
            ->and(is_dir($plan->target))
            ->toBeFalse();
    });

    it('revalidates target existence when a target appears after plan()', function (): void {
        $target = skill_actions_target($this->tempHome);
        $plan = $this->actions->plan(skill_install_request());

        expect($plan)
            ->toBeInstanceOf(SkillInstallPlan::class)
            ->and($plan->targetExistsAtPlan)
            ->toBeFalse()
            ->and($plan->force)
            ->toBeFalse();

        File::ensureDirectoryExists($target);
        file_put_contents(filename: $target.'/SKILL.md', data: "# stale\n");

        $result = $this->actions->install($plan);

        expect($result)
            ->toBeInstanceOf(SkillInstallFailure::class)
            ->and($result->code)
            ->toBe('validation_failed')
            ->and($result->meta['field'])
            ->toBe('force')
            ->and($result->meta['reason'])
            ->toBe('destructive_consent_required')
            ->and($result->meta['target'])
            ->toBe($target)
            ->and(file_get_contents($target.'/SKILL.md'))
            ->toBe("# stale\n");
    });

    it('skips delete when a planned existing target is removed before install()', function (): void {
        $target = skill_actions_target($this->tempHome);
        File::ensureDirectoryExists($target);
        file_put_contents(filename: $target.'/SKILL.md', data: "# stale\n");

        $plan = $this->actions->plan(skill_install_request(force: true));

        expect($plan)
            ->toBeInstanceOf(SkillInstallPlan::class)
            ->and($plan->targetExistsAtPlan)
            ->toBeTrue();

        File::deleteDirectory($target);

        $result = $this->actions->install($plan);

        expect($result)
            ->toBeInstanceOf(SkillInstallResult::class)
            ->and(file_get_contents($target.'/SKILL.md'))
            ->toBe("# Orbit skill\n");
    });

    it('installs after consent by copying force onto the same plan', function (): void {
        $target = skill_actions_target($this->tempHome);
        File::ensureDirectoryExists($target);
        file_put_contents(filename: $target.'/SKILL.md', data: "# stale\n");

        $plan = $this->actions->plan(skill_install_request());

        expect($plan)
            ->toBeInstanceOf(SkillInstallPlan::class)
            ->and($plan->force)
            ->toBeFalse()
            ->and($plan->targetExistsAtPlan)
            ->toBeTrue();

        $result = $this->actions->install($plan->withForce());

        expect($result)
            ->toBeInstanceOf(SkillInstallResult::class)
            ->and($plan->force)
            ->toBeFalse()
            ->and(file_get_contents($target.'/SKILL.md'))
            ->toBe("# Orbit skill\n")
            ->and($this->resolveCounter->resolveCalls)
            ->toBe(1);
    });
});
