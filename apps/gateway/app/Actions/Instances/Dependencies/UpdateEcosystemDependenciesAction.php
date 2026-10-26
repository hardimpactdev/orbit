<?php

declare(strict_types=1);

namespace App\Actions\Instances\Dependencies;

use App\Domain\Instances\Dependencies\DependencyEcosystem;
use App\Domain\Instances\Dependencies\DependencyUpdateInspection;
use App\Domain\Instances\Dependencies\DependencyUpdateStepResult;
use App\Domain\Instances\InstanceSourceLayout;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Models\Instance;
use Throwable;

/**
 * Checks and updates one package ecosystem of a development checkout through the Node agent's
 * `instance.probe-dependency-update` and `instance.update-dependencies`
 * (docs/reference/instance-dependency-contracts.md). Composer runs itself; npm, pnpm, and Bun run through a verified
 * Vite+. A present Yarn project refuses, because no Yarn update is supported.
 */
final readonly class UpdateEcosystemDependenciesAction
{
    /** Vite+ versions verified to delegate `vp update --no-save` to each JavaScript manager within its constraints. */
    private const array SUPPORTED_VITE_PLUS_VERSIONS = ['0.3.0'];

    /** The Vite+ installs Orbit manages: its own, and the account's layouts. */
    private const array VITE_PLUS_PATHS = ['/usr/local/bin/vp', '/opt/orbit/vite-plus/bin/vp'];

    private const array VITE_PLUS_HOME_SUFFIXES = [
        '/.vite-plus/bin/vp',
        '/.vite-plus/current/bin/vp',
        '/.local/share/vite-plus/bin/vp',
        '/.local/share/vite-plus/current/bin/vp',
    ];

    private const array PROBE_ERRORS = [
        'dependencies.unsafe_source',
        'dependencies.unreadable_source',
        'dependencies.invalid_manifest',
        'dependencies.unsupported_format',
        'dependencies.unsupported_layout',
        'dependencies.ambiguous_manager',
    ];

    /** The update itself runs at most 600 seconds; the rest leaves time for its stop and its result. */
    private const int UPDATE_DEADLINE_SECONDS = 630;

    public function __construct(private AgentCommandRunner $agents) {}

    public function inspect(Instance $instance, string $ecosystem): DependencyUpdateInspection
    {
        if ($instance->placedOnAppProd()) {
            return DependencyUpdateInspection::failed('dependencies.production_update_forbidden');
        }
        if (! $this->safe($instance)) {
            return DependencyUpdateInspection::failed('dependencies.unsafe_source');
        }

        try {
            $payload = $this->agents->run($instance->node, 'instance.probe-dependency-update', [
                'directory' => $instance->dependencyDirectory(),
                'ecosystem' => $ecosystem,
            ]);
        } catch (AgentRunnerFailure $failure) {
            return DependencyUpdateInspection::failed($failure->kind === AgentRunnerFailure::Timeout ? 'dependencies.update_timeout' : 'dependencies.unreadable_source');
        } catch (Throwable) {
            return DependencyUpdateInspection::failed('dependencies.unreadable_source');
        }

        $error = $payload['error'] ?? null;
        if ($error !== null) {
            return DependencyUpdateInspection::failed(in_array($error, self::PROBE_ERRORS, true) ? $error : 'dependencies.unreadable_source');
        }

        return match ($payload['status'] ?? null) {
            'absent' => DependencyUpdateInspection::absent(),
            'incomplete' => DependencyUpdateInspection::failed('dependencies.incomplete_source'),
            'present' => match ($ecosystem) {
                'composer' => DependencyUpdateInspection::ready(),
                'yarn' => DependencyUpdateInspection::failed('dependencies.unsupported_format'),
                default => $this->delegation($payload['vp'] ?? null),
            },
            default => DependencyUpdateInspection::failed('dependencies.unreadable_source'),
        };
    }

    public function apply(Instance $instance, string $ecosystem, ?string $vitePlus = null): DependencyUpdateStepResult
    {
        $slot = $ecosystem === 'composer' ? DependencyEcosystem::Composer : DependencyEcosystem::Npm;
        if (! $this->safe($instance)) {
            return DependencyUpdateStepResult::failed($slot, 'dependencies.unsafe_source', false);
        }

        try {
            $result = $this->agents->run($instance->node, 'instance.update-dependencies', [
                'directory' => $instance->dependencyDirectory(),
                'ecosystem' => $ecosystem,
                'vp' => $vitePlus,
            ], self::UPDATE_DEADLINE_SECONDS);
        } catch (AgentRunnerFailure $failure) {
            return DependencyUpdateStepResult::failed($slot, $failure->kind === AgentRunnerFailure::Timeout ? 'dependencies.update_timeout' : 'dependencies.update_failed', true);
        } catch (Throwable) {
            return DependencyUpdateStepResult::failed($slot, 'dependencies.update_failed', true);
        }

        return match ($result['exit_code'] ?? null) {
            0 => DependencyUpdateStepResult::succeeded($slot),
            124 => DependencyUpdateStepResult::failed($slot, 'dependencies.update_timeout', true),
            default => DependencyUpdateStepResult::failed($slot, 'dependencies.update_failed', true),
        };
    }

    /** A JavaScript update runs only through a verified Vite+ that Orbit manages. */
    private function delegation(mixed $vitePlus): DependencyUpdateInspection
    {
        if ($vitePlus === null) {
            return DependencyUpdateInspection::failed('dependencies.unsupported_delegation');
        }
        $path = is_array($vitePlus) ? ($vitePlus['path'] ?? null) : null;
        $version = is_array($vitePlus) ? ($vitePlus['version'] ?? null) : null;
        if (! is_string($path) || ! is_string($version) || ! $this->managedVitePlusPath($path)) {
            return DependencyUpdateInspection::failed('dependencies.unreadable_source');
        }

        return in_array($version, self::SUPPORTED_VITE_PLUS_VERSIONS, true)
            ? DependencyUpdateInspection::ready($path)
            : DependencyUpdateInspection::failed('dependencies.unsupported_delegation');
    }

    private function safe(Instance $instance): bool
    {
        $path = $instance->dependencyDirectory();

        return $instance->placedOnAppDev()
            && in_array($instance->source_layout, array_column(InstanceSourceLayout::cases(), 'value'), true)
            && str_starts_with($path, '/') && ! str_contains($path, "\0");
    }

    private function managedVitePlusPath(string $path): bool
    {
        if (! str_starts_with($path, '/') || preg_match('/[[:cntrl:]]/', $path) === 1) {
            return false;
        }

        return in_array($path, self::VITE_PLUS_PATHS, true)
            || array_any(self::VITE_PLUS_HOME_SUFFIXES, static fn (string $suffix): bool => str_ends_with($path, $suffix));
    }
}
