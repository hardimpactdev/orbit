<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\UbuntuRelease;
use App\Domain\RemotePrograms\ProgramResult;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentInvocation;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\PhpBuildInstaller;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The PHP step of an Instance. It installs the recipe's exact PHP build of each selected version through
 * `php.install-build`, the one installer (ADR 0216), and then publishes the Orbit runtime module of each version.
 * An Instance step keeps PHP packages outside the recipe and installs with --no-remove, so it never removes a site's
 * extension; recipe convergence refuses such packages instead.
 */
final readonly class RemotePhpPackageManager
{
    private const string EXPECTED_DISTRIBUTION = 'ubuntu';

    /** The installer keeps every downloaded and repacked file here: the files are the rollback. */
    public const string CACHE_DIRECTORY = '/var/cache/orbit/php-packages';

    /** @var array{source_step: string, source_error: string, install_step: string, install_error: string} */
    private const array APP_DEV_FAILURE_CONTRACT = [
        'source_step' => 'php-package-source',
        'source_error' => 'app-dev.php_package_source_unavailable',
        'install_step' => 'php-fpm-install',
        'install_error' => 'app-dev.php_install_failed',
    ];

    /** @var array{source_step: string, source_error: string, install_step: string, install_error: string} */
    private const array APP_PROD_FAILURE_CONTRACT = [
        'source_step' => 'app-prod-php-package-source',
        'source_error' => 'app-prod.php_package_source_unavailable',
        'install_step' => 'app-prod-php-fpm-install',
        'install_error' => 'app-prod.php_install_failed',
    ];

    /** Installer outcomes that mean the source of the build, not its installation, failed. */
    private const array SourceOutcomes = ['platform_unsupported', 'conflicting_source', 'download_failed'];

    public function __construct(
        private ?MachineRecipe $recipe = null,
        private ?AgentCommandRunner $agents = null,
    ) {}

    /** @param Collection<int, string> $versions */
    public function installForAppDev(Node $node, Collection $versions): void
    {
        $this->install($node, $versions, RoleName::AppDev, profile: 'app-dev');
    }

    /** @param Collection<int, string> $versions */
    public function installForInstance(
        Node $node,
        Collection $versions,
        RoleName $role,
    ): void {
        $profile = $role === RoleName::AppDev ? 'app-dev' : self::runtimeProfile($node);

        $this->install($node, $versions, $role, $profile);
    }

    /** @param Collection<int, string> $versions */
    public function installForAppProd(Node $node, Collection $versions): void
    {
        $profile = self::runtimeProfile($node);

        $this->install($node, $versions, RoleName::AppProd, $profile);
    }

    /**
     * Installs the build without restarting masters, then publishes the shared runtime settings.
     * A changed module gracefully reloads valid active masters; package changes wait for pool publication.
     *
     * @param  Collection<int, string>  $versions
     */
    public function installForProductionRuntime(Node $node, Collection $versions): void
    {
        if ($versions->isEmpty()) {
            return;
        }

        $this->runInstaller($node, array_values($versions->all()), self::APP_PROD_FAILURE_CONTRACT, RoleName::AppProd, $this->pcov($node), fpm: 'none', opentelemetry: $this->opentelemetry($node));
        $this->configureRuntime($node, array_values($versions->all()), RoleName::AppProd, self::runtimeProfile($node));
    }

    public function packagesOnlyRecipeRevision(): string
    {
        return hash('sha256', ($this->recipe ?? new RecipeLoader()->load())->canonicalJson);
    }

    /**
     * Immutable package-only input for the Node child Operation. It never restarts an FPM master.
     *
     * @param  list<string>  $versions
     * @return array<string, mixed>
     */
    public function packagesOnlyInputForAppProd(Node $node, array $versions): array
    {
        $recipe = $this->recipe ?? new RecipeLoader()->load();
        foreach ($versions as $version) {
            if (! isset($recipe->php[$version])) {
                throw new RuntimeConvergenceException(
                    step: self::APP_PROD_FAILURE_CONTRACT['source_step'],
                    errorCode: self::APP_PROD_FAILURE_CONTRACT['source_error'],
                    message: "The machine software recipe has no PHP {$version} build.",
                );
            }
        }

        return PhpBuildInstaller::input($recipe, $versions, $this->pcov($node), extras: 'keep', fpm: 'none',
            opentelemetry: $this->opentelemetry($node), release: $this->release(RoleName::AppProd));
    }

    /** @return array<string, mixed> Immutable runtime-module input for a composed production deploy. */
    public function runtimeInputForAppProd(Node $node, string $version): array
    {
        return $this->profileInput($version, self::runtimeProfile($node), RoleName::AppProd);
    }

    /**
     * The programs that install the `app-dev` PHP build of `$versions` on an `app-dev` Node, as
     * {@see self::installForAppDev()} runs them: the installer first, then the runtime module of each version.
     *
     * @param  list<string>  $versions
     * @return array<string, RemoteCommand> keyed `install` and the version
     */
    public function appDevPrograms(Node $node, array $versions): array
    {
        $programs = ['install' => $this->installerCommand($node, $versions, RoleName::AppDev, pcov: true, fpm: 'shared', opentelemetry: in_array('app-dev', ($this->recipe ?? new RecipeLoader()->load())->phpPolicy->opentelemetryProfiles, true))];
        foreach (array_values(array_unique($versions)) as $version) {
            $programs[$version] = app(AgentInvocation::class)->command($node, 'php.configure-runtime', $this->profileInput($version, 'app-dev', RoleName::AppDev));
        }

        return $programs;
    }

    /**
     * @param  Collection<int, string>  $versions
     */
    private function install(
        Node $node,
        Collection $versions,
        RoleName $role,
        string $profile,
    ): void {
        $failure = match ($role) {
            RoleName::AppDev => self::APP_DEV_FAILURE_CONTRACT,
            RoleName::AppProd => self::APP_PROD_FAILURE_CONTRACT,
            default => throw new \LogicException('PHP package installation requires an application role.'),
        };
        if ($versions->isEmpty()) {
            return;
        }

        $selected = array_values(array_unique($versions->all()));
        $this->runInstaller($node, $selected, $failure, $role, pcov: $this->pcov($node), fpm: 'shared', opentelemetry: $this->opentelemetry($node));

        $this->configureRuntime($node, $selected, $role, $profile);
    }

    public static function runtimeProfile(Node $node): string
    {
        return $node->roles->pluck('role')->contains(RoleName::AppDev) ? 'app-dev' : 'app-prod';
    }

    /** @param list<string> $versions */
    public function configureForAppNode(Node $node, array $versions): bool
    {
        $profile = self::runtimeProfile($node);
        $role = $profile === 'app-dev' ? RoleName::AppDev : RoleName::AppProd;

        return $this->configureRuntime($node, $versions, $role, $profile);
    }

    /** @return array<string, mixed> */
    public function runtimeInput(Node $node, string $version): array
    {
        $profile = self::runtimeProfile($node);

        return $this->profileInput($version, $profile, $profile === 'app-dev' ? RoleName::AppDev : RoleName::AppProd);
    }

    /** @param list<string> $versions */
    private function configureRuntime(Node $node, array $versions, RoleName $role, string $profile): bool
    {
        $failure = $role === RoleName::AppDev ? self::APP_DEV_FAILURE_CONTRACT : self::APP_PROD_FAILURE_CONTRACT;
        $changed = false;
        foreach (array_unique($versions) as $version) {
            $result = AgentStep::run($this->agents ?? app(AgentCommandRunner::class), $node, 'php.configure-runtime',
                $this->profileInput($version, $profile, $role),
                step: $failure['install_step'], errorCode: $failure['install_error'], label: 'PHP runtime');
            $this->logOutcome($node, new ProgramResult('configured', $result, ''));
            $changed = ($result['changed'] ?? false) === true || $changed;
        }

        return $changed;
    }

    /**
     * @param  list<string>  $versions
     * @param  array{source_step: string, source_error: string, install_step: string, install_error: string}  $failure
     * @param  'none'|'shared'  $fpm
     */
    private function runInstaller(
        Node $node,
        array $versions,
        array $failure,
        RoleName $role,
        bool $pcov,
        string $fpm,
        bool $opentelemetry,
    ): void {
        $recipe = $this->recipe ?? new RecipeLoader()->load();
        foreach ($versions as $version) {
            if (! isset($recipe->php[$version])) {
                throw new RuntimeConvergenceException(
                    step: $failure['source_step'],
                    errorCode: $failure['source_error'],
                    message: "The machine software recipe has no PHP {$version} build.",
                );
            }
        }
        try {
            $outcome = new PhpBuildInstaller($this->agents ?? app(AgentCommandRunner::class))->run(
                $node, $recipe, $versions, 'apply', $pcov, 'keep', $fpm, $opentelemetry, $this->release($role),
            );
        } catch (AgentRunnerFailure $exception) {
            if ($exception->kind === AgentRunnerFailure::Timeout) {
                throw $exception;
            }
            throw new RuntimeConvergenceException(
                step: $failure['install_step'], errorCode: $failure['install_error'],
                message: "The PHP build installer ended without a result on node [{$node->name}].",
                previous: $exception, result: $exception->result,
            );
        }
        if (in_array($outcome->outcome, PhpBuildInstaller::Succeeded, true)) {
            $this->logOutcome($node, $outcome);

            return;
        }
        $source = in_array($outcome->outcome, self::SourceOutcomes, true);
        throw new RuntimeConvergenceException(
            step: $source ? $failure['source_step'] : $failure['install_step'],
            errorCode: $source ? $failure['source_error'] : $failure['install_error'],
            message: $outcome->outcome === 'platform_unsupported'
                ? UbuntuRelease::unsupportedText($outcome->string('id') ?? '', $outcome->string('codename') ?? '')
                : PhpBuildInstaller::describe($outcome),
            result: AgentStep::output(new AgentCommandRefused('php.install-build', 'php.build_'.$outcome->outcome, PhpBuildInstaller::describe($outcome), $outcome->data)),
        );
    }

    /** @param  list<string>  $versions */
    private function installerCommand(Node $node, array $versions, RoleName $role, bool $pcov, string $fpm, bool $opentelemetry): RemoteCommand
    {
        $recipe = $this->recipe ?? new RecipeLoader()->load();
        $input = PhpBuildInstaller::input($recipe, $versions, $pcov, extras: 'keep', fpm: $fpm, release: $this->release($role), opentelemetry: $opentelemetry);

        return app(AgentInvocation::class)->command($node, 'php.install-build', $input);
    }

    /**
     * PCOV follows the Node's roles, as in recipe convergence, so every step on one Node asks for the same placement and
     * an Instance step never toggles PCOV or restarts PHP-FPM for it (ADR 0216).
     */
    private function pcov(Node $node): bool
    {
        $roles = $node->roles->map(static fn ($role): string => $role->role->value)->all();
        $recipe = $this->recipe ?? new RecipeLoader()->load();

        return array_intersect($roles, $recipe->phpPolicy->pcovProfiles) !== [];
    }

    private function opentelemetry(Node $node): bool
    {
        $roles = $node->roles->map(static fn ($role): string => $role->role->value)->all();
        $recipe = $this->recipe ?? new RecipeLoader()->load();

        return array_intersect($roles, $recipe->phpPolicy->opentelemetryProfiles) !== [];
    }

    private function logOutcome(Node $node, ProgramResult $outcome): void
    {
        if ($outcome->outcome === 'current') {
            Log::info('PHP packages match the recipe; skipped the package download.', ['node' => $node->name]);
        }
        $stale = $outcome->data['stale_masters'] ?? [];
        if (is_array($stale) && $stale !== []) {
            Log::warning('Dedicated PHP-FPM masters were not restarted or reloaded; they may still use the previous PHP build or settings.', ['node' => $node->name, 'masters' => $stale]);
        }
        $deferred = $outcome->data['deferred'] ?? [];
        if (is_array($deferred) && $deferred !== []) {
            Log::warning('PHP-FPM masters failed configuration validation; deferred their restart or reload until their configuration is repaired.', ['node' => $node->name, 'masters' => $deferred]);
        }
    }

    /** @return array<string, mixed> */
    private function profileInput(string $version, string $profile, RoleName $role): array
    {
        return ['version' => $version, 'runtime_ini' => new PhpFpmRuntimeIniRenderer()->render($profile), 'release' => $this->release($role)];
    }

    /** @return array{id: string, codenames: list<string>} */
    private function release(RoleName $role): array
    {
        return ['id' => self::EXPECTED_DISTRIBUTION, 'codenames' => array_map(static fn (UbuntuRelease $release): string => $release->value, UbuntuRelease::forRole($role))];
    }
}
