<?php

declare(strict_types=1);

/**
 * @mago-expect lint:halstead
 */

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->tempRoot = sys_get_temp_dir().'/orbit-skill-install-test-'.bin2hex(random_bytes(4));
    $this->tempHome = $this->tempRoot.'/home';
    $this->checkoutRoot = $this->tempRoot.'/checkout';
    $this->sourceSkillPath = $this->checkoutRoot.'/.agents/skills/orbit';

    File::deleteDirectory($this->tempRoot);
    File::ensureDirectoryExists($this->sourceSkillPath);
    file_put_contents(filename: $this->sourceSkillPath.'/SKILL.md', data: "# Orbit skill\n");

    $this->previousHome = getenv('HOME');
    $this->previousInstallPath = getenv('ORBIT_INSTALL_PATH');

    putenv("HOME={$this->tempHome}");
    putenv("ORBIT_INSTALL_PATH={$this->checkoutRoot}");
});

afterEach(function (): void {
    $this->previousHome === false ? putenv('HOME') : putenv("HOME={$this->previousHome}");
    $this->previousInstallPath === false
        ? putenv('ORBIT_INSTALL_PATH')
        : putenv("ORBIT_INSTALL_PATH={$this->previousInstallPath}");

    File::deleteDirectory($this->tempRoot);
});

/**
 * @return array{int, string}
 */
function run_skill_install_command(object $test, array $params = []): array
{
    return runCommand(test: $test, command: 'skill:install', params: $params);
}

function skill_install_target(string $home, string $provider): string
{
    return match ($provider) {
        'codex' => rtrim(string: $home, characters: '/').'/.agents/skills/orbit',
        'claude' => rtrim(string: $home, characters: '/').'/.claude/skills/orbit',
        'antigravity' => rtrim(string: $home, characters: '/').'/.gemini/config/skills/orbit',
        'grok' => rtrim(string: $home, characters: '/').'/.grok/skills/orbit',
        default => throw new InvalidArgumentException("Unknown provider {$provider}"),
    };
}

describe('skill:install', function (): void {
    it('is registered with the expected signature options', function (): void {
        $command = app(\Illuminate\Contracts\Console\Kernel::class)->all()['skill:install'] ?? null;

        expect($command)->not->toBeNull()->and($command->getDefinition()->getOptions())->toHaveKeys(['force', 'json']);
    });

    describe('validation', function (): void {
        it('fails with validation_failed when no provider or path is provided', function (): void {
            [$exitCode, $output] = run_skill_install_command($this, ['--json' => true]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('validation_failed');
        });

        it('fails with validation_failed when an explicit path is followed by another path', function (): void {
            [$exitCode, $output] = run_skill_install_command($this, [
                'provider' => 'unknown',
                'path' => '/tmp/custom-skill',
                '--json' => true,
            ]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)
                ->toBe(1)
                ->and($decoded['error']['code'])
                ->toBe('validation_failed')
                ->and($decoded['error']['meta']['field'])
                ->toBe('path')
                ->and($decoded['error']['meta']['reason'])
                ->toBe('unexpected_path');
        });

        it('fails with validation_failed when a provider default needs HOME and HOME is missing', function (): void {
            putenv('HOME');

            [$exitCode, $output] = run_skill_install_command($this, [
                'provider' => 'codex',
                '--json' => true,
            ]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)
                ->toBe(1)
                ->and($decoded['error']['code'])
                ->toBe('validation_failed')
                ->and($decoded['error']['meta']['reason'])
                ->toBe('missing_home');
        });

        it('fails with validation_failed when the source skill directory is missing', function (): void {
            File::deleteDirectory($this->sourceSkillPath);

            [$exitCode, $output] = run_skill_install_command($this, [
                'provider' => 'codex',
                '--json' => true,
            ]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)
                ->toBe(1)
                ->and($decoded['error']['code'])
                ->toBe('validation_failed')
                ->and($decoded['error']['meta']['field'])
                ->toBe('source')
                ->and($decoded['error']['meta']['reason'])
                ->toBe('missing_source')
                ->and($decoded['error']['meta']['source'])
                ->toBe($this->sourceSkillPath);
        });
    });

    describe('provider defaults', function (): void {
        it('installs to each provider default target', function (string $provider): void {
            $target = skill_install_target($this->tempHome, $provider);

            [$exitCode, $output] = run_skill_install_command($this, [
                'provider' => $provider,
                '--json' => true,
            ]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)
                ->toBe(0)
                ->and($decoded['success']['data']['provider'])
                ->toBe($provider)
                ->and($decoded['success']['data']['target'])
                ->toBe($target)
                ->and($decoded['success']['data']['action'])
                ->toBe('installed')
                ->and(is_file($target.'/SKILL.md'))
                ->toBeTrue();
        })->with(['codex', 'claude', 'antigravity', 'grok']);
    });

    describe('explicit path', function (): void {
        it('treats a lone positional as an explicit target path when it is not a known provider', function (): void {
            $target = $this->tempRoot.'/custom/orbit-skill';

            [$exitCode, $output] = run_skill_install_command($this, [
                'provider' => $target,
                '--json' => true,
            ]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)
                ->toBe(0)
                ->and($decoded['success']['data']['provider'])
                ->toBeNull()
                ->and($decoded['success']['data']['target'])
                ->toBe($target)
                ->and(is_file($target.'/SKILL.md'))
                ->toBeTrue();
        });

        it('installs to an explicit path when provider and path are both provided', function (): void {
            $target = $this->tempRoot.'/providers/codex-custom';

            [$exitCode, $output] = run_skill_install_command($this, [
                'provider' => 'codex',
                'path' => $target,
                '--json' => true,
            ]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)
                ->toBe(0)
                ->and($decoded['success']['data']['provider'])
                ->toBe('codex')
                ->and($decoded['success']['data']['target'])
                ->toBe($target)
                ->and(is_file($target.'/SKILL.md'))
                ->toBeTrue();
        });
    });

    describe('overwrite protection', function (): void {
        it('fails before mutating an existing target without --force', function (): void {
            $target = skill_install_target(home: $this->tempHome, provider: 'codex');
            File::ensureDirectoryExists($target);
            file_put_contents(filename: $target.'/SKILL.md', data: "# stale\n");

            [$exitCode, $output] = run_skill_install_command($this, [
                'provider' => 'codex',
                '--json' => true,
            ]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)
                ->toBe(1)
                ->and($decoded['error']['code'])
                ->toBe('validation_failed')
                ->and($decoded['error']['meta']['field'])
                ->toBe('force')
                ->and($decoded['error']['meta']['reason'])
                ->toBe('destructive_consent_required')
                ->and($decoded['error']['meta']['target'])
                ->toBe($target)
                ->and(file_get_contents($target.'/SKILL.md'))
                ->toBe("# stale\n");
        });

        it('prompts before replacing a resolved existing target', function (): void {
            $target = skill_install_target(home: $this->tempHome, provider: 'codex');
            File::ensureDirectoryExists($target);
            file_put_contents(filename: $target.'/SKILL.md', data: "# stale\n");

            $this
                ->artisan('skill:install', ['provider' => 'codex'])
                ->expectsConfirmation("Replace existing Orbit skill target '{$target}'?", 'yes')
                ->assertSuccessful();

            expect(file_get_contents($target.'/SKILL.md'))->toBe("# Orbit skill\n");
        });

        it('does not replace a resolved existing target when confirmation is declined', function (): void {
            $target = skill_install_target(home: $this->tempHome, provider: 'codex');
            File::ensureDirectoryExists($target);
            file_put_contents(filename: $target.'/SKILL.md', data: "# stale\n");

            $this
                ->artisan('skill:install', ['provider' => 'codex'])
                ->expectsConfirmation("Replace existing Orbit skill target '{$target}'?", 'no')
                ->expectsOutput('validation_failed: Operation cancelled.')
                ->assertFailed();

            expect(file_get_contents($target.'/SKILL.md'))->toBe("# stale\n");
        });

        it('overwrites an existing target when --force is provided', function (): void {
            $target = skill_install_target(home: $this->tempHome, provider: 'codex');
            File::ensureDirectoryExists($target);
            file_put_contents(filename: $target.'/SKILL.md', data: "# stale\n");

            [$exitCode, $output] = run_skill_install_command($this, [
                'provider' => 'codex',
                '--force' => true,
                '--json' => true,
            ]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)
                ->toBe(0)
                ->and($decoded['success']['data']['action'])
                ->toBe('installed')
                ->and(file_get_contents($target.'/SKILL.md'))
                ->toBe("# Orbit skill\n");
        });

        it('overwrites an existing file target when --force is provided', function (): void {
            $target = $this->tempRoot.'/file-target';
            File::ensureDirectoryExists(dirname($target));
            file_put_contents(filename: $target, data: 'stale');

            [$exitCode, $output] = run_skill_install_command($this, [
                'provider' => $target,
                '--force' => true,
                '--json' => true,
            ]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)
                ->toBe(0)
                ->and($decoded['success']['data']['target'])
                ->toBe($target)
                ->and(is_dir($target))
                ->toBeTrue()
                ->and(file_get_contents($target.'/SKILL.md'))
                ->toBe("# Orbit skill\n");
        });
    });

    describe('local-only behavior', function (): void {
        it('does not call the gateway', function (): void {
            fakeGateway(fakeSuccessEnvelope(['nodes' => []]));

            [$exitCode] = run_skill_install_command($this, [
                'provider' => 'codex',
                '--json' => true,
            ]);

            expect($exitCode)->toBe(0);
            Http::assertNothingSent();
        });
    });

    describe('human output', function (): void {
        it('renders key value lines on success', function (): void {
            [$exitCode, $output] = run_skill_install_command($this, ['provider' => 'codex']);

            expect($exitCode)
                ->toBe(0)
                ->and($output)
                ->toContain('action: installed')
                ->and($output)
                ->toContain('provider: codex');
        });

        it('renders code:message on validation failure', function (): void {
            [$exitCode, $output] = run_skill_install_command($this);

            expect($exitCode)->toBe(1)->and($output)->toStartWith('validation_failed:');
        });
    });
});
