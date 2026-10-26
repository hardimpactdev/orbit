<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentCommands\AgentGuest;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AgentCommands\PiArtifactDelivery;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\TaskVms\RecipeImagePrograms;
use App\Jobs\TaskVms\ImageBuild\BuildRecipeImageStep;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeGuestAgentCommandRunner;

function nativePhaseFixture(?CommandDeadline $deadline = null): array
{
    $guest = AgentGuest::incus(new Node(['name' => 'image-host', 'platform' => 'linux', 'architecture' => 'x86_64']), 'default', 'image-builder');
    $agents = new FakeGuestAgentCommandRunner;
    $deadline ??= new CommandDeadline;
    $pi = new PiArtifactDelivery(new FakeAgentCommandRunner, $agents, $deadline);
    $programs = new RecipeImagePrograms($deadline, $agents, app(AgentDelivery::class), $pi);

    return [$programs, $agents, $guest, new RecipeLoader()->load(), $pi];
}

function nativePhaseObservation(string $state, string $invocation = '11111111111111111111111111111111'): array
{
    return ['boot_id' => '11111111-1111-4111-8111-111111111111', 'unit' => 'orbit-recipe-image-test.service', 'state' => $state,
        'invocation_id' => $state === 'missing' ? null : $invocation, 'exit_code' => $state === 'failed' ? 100 : null,
        'reason' => $state === 'failed' ? 'package install failed' : null, 'log_tail' => $state === 'failed' ? 'retained diagnostic' : ''];
}

it('sends the complete base recipe as typed data with the delivery pin and one PHP installer', function (): void {
    [$programs, $agents, $guest, $recipe] = nativePhaseFixture();
    $agents->answer = static fn ($guest, string $command): array => nativePhaseObservation($command === 'image.inspect-phase' ? 'missing' : 'complete');
    $state = [];

    expect($programs->poll($guest, $recipe, 'base', 'packages', '23', $state))->toBeTrue();

    expect(array_column($agents->calls, 'command'))->toBe(['image.inspect-phase', 'image.start-phase']);
    $payload = $agents->calls[1]['input']['recipe'];
    expect($payload['agent_sha256'])->toBe(app(AgentDelivery::class)->pin($guest));
    expect($payload['recipe']['vp_lock'])->toBe(file_get_contents(resource_path('machine-software/vite-plus-package-lock.json')));
    expect($payload['php'])->not->toHaveKeys(['mode', 'program', 'bootstrap']);
    expect($payload['php']['fpm'])->toBe('none');
    expect($payload['php']['extras'])->toBe('refuse');
    expect($payload['recipe']['tools'])->not->toHaveKeys(['rust', 'cargo', 'orbit-agent', 'pi', 'php8.4-cli', 'php8.5-cli']);
    expect($payload['rust'])->toHaveKeys(['rust', 'cargo', 'rust-std', 'clippy', 'rustfmt']);
    $document = json_decode($recipe->json, true, flags: JSON_THROW_ON_ERROR);
    expect($payload['recipe']['architecture'])->toBe('amd64')
        ->and($payload['recipe']['snapshot'])->toBe($document['ubuntu']['snapshot']);
    foreach (RecipeLoader::IncusPackages as $name) {
        expect($payload['recipe']['tools'][$name]['version'])->toBe($document['tools'][$name]['artifacts']['amd64']['version'])
            ->and($payload['recipe']['tools'][$name]['artifacts'])->toBe($document['tools'][$name]['artifacts']);
    }
});

it('installs OpenTelemetry only in app-dev image profiles', function (string $profile, bool $enabled): void {
    [$programs, $agents, $guest, $recipe] = nativePhaseFixture();
    $agents->answer = static fn ($guest, string $command): array => nativePhaseObservation($command === 'image.inspect-phase' ? 'missing' : 'complete');
    $state = [];

    $programs->poll($guest, $recipe, $profile, 'packages', '23', $state);

    $payload = $agents->calls[1]['input']['recipe'];
    expect($payload['php']['opentelemetry'])->toBe($enabled);
    foreach (['8.4', '8.5'] as $version) {
        // The PHP installer owns every PHP package, so the general tool list never carries it.
        expect($payload['recipe']['tools'])->not->toHaveKey("php{$version}-opentelemetry")
            ->and(in_array("php{$version}-opentelemetry", array_column($payload['php']['versions'][$version]['packages'], 'name'), true))->toBe($enabled)
            ->and($payload['php']['versions'][$version]['extensions'])->not->toContain('opentelemetry');
    }
})->with(['app-dev' => ['app-dev', true], 'app-prod' => ['app-prod', false], 'base' => ['base', false], 'ci-runner' => ['ci-runner', false]]);

it('refuses a stored recipe from another delivery before observing or restarting a phase', function (): void {
    [$programs, $agents, $guest, $recipe] = nativePhaseFixture();
    $document = json_decode($recipe->json, true, flags: JSON_THROW_ON_ERROR);
    $document['agent']['linux-x86_64'] = str_repeat('e', 64);
    $stale = new RecipeLoader()->decode(json_encode($document, JSON_THROW_ON_ERROR));
    $state = [];

    expect(fn () => $programs->poll($guest, $stale, 'base', 'agent', '23', $state))
        ->toThrow(TaskVmException::class, 'stored recipe agent pin differs');
    expect(fn () => $programs->verify($guest, $stale, 'base'))
        ->toThrow(TaskVmException::class, 'stored recipe agent pin differs');
    expect($agents->calls)->toBe([]);
    expect($state)->toBe([]);
});

it('persists the boot fence before issuing a start and observes a lost acknowledgement', function (): void {
    [$programs, $agents, $guest, $recipe] = nativePhaseFixture();
    $saved = [];
    $started = false;
    $agents->answer = function ($guest, string $command, array $input) use (&$started, &$saved): array {
        if ($command === 'image.start-phase') {
            expect($saved['boot_id'])->toBe($input['boot_id']);
            expect($saved['attempt'])->toBe(1);
            $started = true;
            throw new AgentRunnerFailure($command, AgentRunnerFailure::Transport, 'SSH reply lost');
        }

        return nativePhaseObservation($started ? 'complete' : 'missing');
    };
    $state = [];
    $save = function (array $value) use (&$saved): void {
        $saved = $value;
    };

    expect($programs->poll($guest, $recipe, 'base', 'packages', '23', $state, $save))->toBeFalse();
    expect($programs->poll($guest, $recipe, 'base', 'packages', '23', $state, $save))->toBeTrue();

    expect(array_column($agents->calls, 'command'))->toBe(['image.inspect-phase', 'image.start-phase', 'image.inspect-phase']);
    expect($state['boot_id'])->toBe($saved['boot_id']);
});

it('restarts a failed invocation only when a later build attempt explicitly resumes it', function (): void {
    [$programs, $agents, $guest, $recipe] = nativePhaseFixture();
    $saved = [];
    $agents->answer = function ($guest, string $command, array $input) use (&$saved): array {
        if ($command === 'image.restart-phase') {
            expect($saved['restart_from'])->toBe($input['invocation_id']);

            return nativePhaseObservation('complete', str_repeat('2', 32));
        }

        return nativePhaseObservation('failed');
    };
    $state = ['attempt' => 1, 'boot_id' => nativePhaseObservation('failed')['boot_id']];
    $save = function (array $value) use (&$saved): void {
        $saved = $value;
    };

    expect(fn () => $programs->poll($guest, $recipe, 'base', 'packages', '23', $state, $save, 1))->toThrow(TaskVmException::class, 'retained diagnostic');
    expect($programs->poll($guest, $recipe, 'base', 'packages', '23', $state, $save, 2))->toBeTrue();

    expect($state['attempt'])->toBe(2);
    expect($state)->not->toHaveKey('restart_from');
    expect(array_column($agents->calls, 'command'))->toBe(['image.inspect-phase', 'image.inspect-phase', 'image.restart-phase']);
});

it('keeps restart intent after a lost reply and adopts the new invocation on the next poll', function (): void {
    [$programs, $agents, $guest, $recipe] = nativePhaseFixture();
    $restarted = false;
    $agents->answer = function ($guest, string $command) use (&$restarted): array {
        if ($command === 'image.restart-phase') {
            $restarted = true;
            throw new AgentRunnerFailure($command, AgentRunnerFailure::Transport, 'Lost restart reply');
        }

        return nativePhaseObservation($restarted ? 'complete' : 'failed', str_repeat($restarted ? '2' : '1', 32));
    };
    $state = ['attempt' => 1, 'boot_id' => nativePhaseObservation('failed')['boot_id']];

    expect($programs->poll($guest, $recipe, 'base', 'packages', '23', $state, attempt: 2))->toBeFalse();
    expect($state['restart_from'])->toBe(str_repeat('1', 32));
    expect($programs->poll($guest, $recipe, 'base', 'packages', '23', $state, attempt: 2))->toBeTrue();
    expect($state)->not->toHaveKey('restart_from');
    expect(array_column($agents->calls, 'command'))->toBe(['image.inspect-phase', 'image.restart-phase', 'image.inspect-phase']);
});

it('adopts a running phase on resume without requesting a restart', function (): void {
    [$programs, $agents, $guest, $recipe] = nativePhaseFixture();
    $agents->answer = static fn (): array => nativePhaseObservation('running');
    $state = ['attempt' => 1];

    expect($programs->poll($guest, $recipe, 'base', 'packages', '23', $state, attempt: 2))->toBeFalse();

    expect($state['attempt'])->toBe(2);
    expect(array_column($agents->calls, 'command'))->toBe(['image.inspect-phase']);
});

it('ends unknown observations at the shared bound and does not start an unobserved phase', function (): void {
    [$programs, $agents, $guest, $recipe] = nativePhaseFixture();
    $agents->malformed = true;
    $agents->answer = static fn (): array => [];
    $state = ['observed_at' => microtime(true) - 1501];

    expect(fn () => $programs->poll($guest, $recipe, 'base', 'packages', '23', $state))->toThrow(TaskVmException::class, 'outcome is unknown');
    expect(array_column($agents->calls, 'command'))->toBe(['image.inspect-phase']);
});

it('maps a reboot refusal to a failed build without another start', function (): void {
    [$programs, $agents, $guest, $recipe] = nativePhaseFixture();
    $agents->answer = static fn ($guest, string $command): array => throw new AgentCommandRefused($command, 'task_vm.image_builder_rebooted', 'The builder rebooted. Run task-vms:build-recipe --retry.');
    $state = [];

    expect(fn () => $programs->poll($guest, $recipe, 'base', 'packages', '23', $state))->toThrow(TaskVmException::class, 'builder rebooted');
    expect(array_column($agents->calls, 'command'))->toBe(['image.inspect-phase']);
});

it('asks the native image verifier to confirm the complete recipe', function (): void {
    [$programs, $agents, $guest, $recipe] = nativePhaseFixture();
    $programs->verify($guest, $recipe, 'base');

    expect(array_column($agents->calls, 'command'))->toBe(['image.verify']);
    expect($agents->calls[0]['input']['identity'])->toBe($recipe->identity);
});

it('delivers bounded Pi chunks and follows the receiver progress after a repeated transfer', function (bool $alreadyReceived): void {
    [, $agents, $guest, , $pi] = nativePhaseFixture();
    $file = tempnam(sys_get_temp_dir(), 'pi-delivery-');
    $bytes = str_repeat('a', 2097152).'tail';
    file_put_contents($file, $bytes);
    $agents->answer = static fn ($guest, string $command, array $input): array => ['received_bytes' => $alreadyReceived ? $input['total'] : $input['offset'] + strlen(base64_decode($input['bytes'])), 'verified' => $alreadyReceived || $input['offset'] > 0];
    try {
        $pi->deliver($guest, $file, hash('sha256', $bytes));
    } finally {
        unlink($file);
    }

    expect($agents->calls)->toHaveCount($alreadyReceived ? 1 : 2);
    expect($agents->calls[0]['command'])->toBe('pi.receive-artifact');
    expect(strlen(base64_decode($agents->calls[0]['input']['bytes'])))->toBe(2097152);
    if (! $alreadyReceived) {
        expect($agents->calls[1]['input']['offset'])->toBe(2097152);
        expect(base64_decode($agents->calls[1]['input']['bytes']))->toBe('tail');
    }
})->with([false, true]);

it('refuses a corrupt local Pi artifact before making a guest request', function (): void {
    [, $agents, $guest, , $pi] = nativePhaseFixture();
    $file = tempnam(sys_get_temp_dir(), 'pi-delivery-');
    file_put_contents($file, 'wrong');
    try {
        expect(fn () => $pi->deliver($guest, $file, hash('sha256', 'expected')))->toThrow(RuntimeException::class, 'digest');
    } finally {
        unlink($file);
    }
    expect($agents->calls)->toBe([]);
});

it('preserves typed verification diagnostics for a build that will delete its guest', function (): void {
    [$programs, $agents, $guest, $recipe] = nativePhaseFixture();
    $agents->answer = static fn ($guest, string $command): array => throw new AgentCommandRefused($command, 'task_vm.image_build_failed', 'Verification failed.', [
        'stage' => 'verify', 'reason' => 'Guest PHP failed', 'exit_code' => 17,
        'stderr' => 'missing module API_TOKEN=private-token',
    ]);

    try {
        $programs->verify($guest, $recipe, 'base');
        test()->fail('Verification must fail.');
    } catch (TaskVmException $error) {
        expect($error->getMessage())->toContain('exit 17', 'Guest PHP failed', 'missing module')->not->toContain('private-token');
        expect($error->imageFailure)->toBe(['phase' => 'base:verify', 'exit_code' => 17, 'stderr' => "Guest PHP failed\nmissing module API_TOKEN=[REDACTED]"]);
    }
});

it('stops Pi chunks at one transfer budget shortened by the surrounding delivery', function (?int $outer): void {
    $elapsed = 0.0;
    $deadline = new CommandDeadline(static function () use (&$elapsed): float {
        return $elapsed;
    });
    if ($outer !== null) {
        $deadline->start($outer);
    }
    [$programs, $agents, $guest, $recipe] = nativePhaseFixture($deadline);
    $directory = sys_get_temp_dir().'/native-image-budget-'.bin2hex(random_bytes(8));
    mkdir($directory.'/sandbox-artifacts', 0700, true);
    $bytes = str_repeat('a', 2097152).'tail';
    $file = $directory.'/sandbox-artifacts/pi-test';
    file_put_contents($file, $bytes);
    config(['orbit.home' => $directory]);
    $document = json_decode($recipe->json, true, flags: JSON_THROW_ON_ERROR);
    $document['tools']['pi']['artifacts']['amd64']['filename'] = 'pi-test';
    $document['tools']['pi']['artifacts']['amd64']['sha256'] = hash('sha256', $bytes);
    $recipe = new RecipeLoader()->decode(json_encode($document, JSON_THROW_ON_ERROR));
    $agents->answer = static function ($guest, string $command, array $input) use (&$elapsed, $agents): array {
        if ($command === 'image.inspect-phase') {
            $elapsed += 10;

            return nativePhaseObservation('missing');
        }
        expect($command)->toBe('pi.receive-artifact');
        $elapsed += array_last($agents->deadlines);

        return ['received_bytes' => strlen(base64_decode($input['bytes'])), 'verified' => false];
    };
    $state = [];
    try {
        expect($programs->poll($guest, $recipe, 'base', 'pi', '23', $state))->toBeFalse();
    } finally {
        unlink($file);
        rmdir($directory.'/sandbox-artifacts');
        rmdir($directory);
    }

    expect(array_column($agents->calls, 'command'))->toBe(['image.inspect-phase', 'pi.receive-artifact']);
    expect($agents->deadlines[1])->toBe($outer === null ? 900.0 : 490.0);
    expect(new BuildRecipeImageStep(23, 'base', 'pi')->timeout)->toBeGreaterThan(RecipeImagePrograms::PhaseSeconds);
})->with([null, 500]);
