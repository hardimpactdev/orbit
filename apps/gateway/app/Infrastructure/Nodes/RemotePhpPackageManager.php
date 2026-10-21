<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\UbuntuRelease;
use App\Domain\RemotePrograms\ProgramResult;
use App\Domain\RemotePrograms\RemoteProgramFailed;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\AppProd\ProductionSshExecutor;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\PhpBuildInstaller;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;
use App\Infrastructure\RemotePrograms\SshProgramRunner;
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
        private RemoteProgramCatalog $catalog = new RemoteProgramCatalog,
    ) {}

    /** @param Collection<int, string> $versions */
    public function installForAppDev(Node $node, Collection $versions, DevelopmentSshExecutor $ssh): void
    {
        $this->install($node, $versions, $ssh, RoleName::AppDev, profile: 'app-dev');
    }

    /** @param Collection<int, string> $versions */
    public function installForInstance(
        Node $node,
        Collection $versions,
        DevelopmentSshExecutor $ssh,
        RoleName $role,
    ): void {
        $profile = $role === RoleName::AppProd && ! $node->roles->pluck('role')->contains(RoleName::AppDev)
            ? 'app-prod'
            : 'app-dev';

        $this->install($node, $versions, $ssh, $role, $profile);
    }

    /** @param Collection<int, string> $versions */
    public function installForAppProd(Node $node, Collection $versions, ProductionSshExecutor $ssh): void
    {
        $profile = $node->roles->pluck('role')->contains(RoleName::AppDev) ? 'app-dev' : 'app-prod';

        $this->install($node, $versions, $ssh, RoleName::AppProd, $profile);
    }

    /**
     * Installs the build for a dedicated production master. It restarts no PHP-FPM service: the production runtime
     * restarts its own master.
     *
     * @param  Collection<int, string>  $versions
     */
    public function installPackagesOnlyForAppProd(Node $node, Collection $versions, ProductionSshExecutor $ssh): void
    {
        if ($versions->isEmpty()) {
            return;
        }

        $this->runInstaller($node, array_values($versions->all()), $ssh, self::APP_PROD_FAILURE_CONTRACT, RoleName::AppProd, $this->pcov($node), fpm: 'none', opentelemetry: $this->opentelemetry($node));
    }

    /**
     * The programs that install the `app-dev` PHP build of `$versions` on an `app-dev` Node, as
     * {@see self::installForAppDev()} runs them: the installer first, then the runtime module of each version.
     *
     * @param  list<string>  $versions
     * @return array<string, RemoteCommand> keyed `install` and the version
     */
    public function appDevPrograms(array $versions): array
    {
        $programs = ['install' => $this->installerCommand($versions, RoleName::AppDev, pcov: true, fpm: 'shared', opentelemetry: in_array('app-dev', ($this->recipe ?? new RecipeLoader()->load())->phpPolicy->opentelemetryProfiles, true))];
        foreach (array_values(array_unique($versions)) as $version) {
            $programs[$version] = $this->profileCommand($version, 'app-dev', RoleName::AppDev);
        }

        return $programs;
    }

    /**
     * @param  Collection<int, string>  $versions
     */
    private function install(
        Node $node,
        Collection $versions,
        DevelopmentSshExecutor|ProductionSshExecutor $ssh,
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
        $this->runInstaller($node, $selected, $ssh, $failure, $role, pcov: $this->pcov($node), fpm: 'shared', opentelemetry: $this->opentelemetry($node));

        foreach ($selected as $version) {
            $ssh->execute(
                $node,
                $this->profileCommand($version, $profile, $role),
                step: $failure['install_step'],
                errorCode: $failure['install_error'],
            );
        }
    }

    /**
     * @param  list<string>  $versions
     * @param  array{source_step: string, source_error: string, install_step: string, install_error: string}  $failure
     */
    private function runInstaller(
        Node $node,
        array $versions,
        DevelopmentSshExecutor|ProductionSshExecutor $ssh,
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
        $command = $this->installerCommand($versions, $role, $pcov, $fpm, $opentelemetry);
        $result = $ssh->execute($node, $command, step: $failure['source_step'], errorCode: $failure['source_error']);
        $program = $this->catalog->program(PhpBuildInstaller::Program);
        try {
            $outcome = SshProgramRunner::pythonResult($program, $result);
        } catch (RemoteProgramFailed $exception) {
            throw new RuntimeConvergenceException(
                step: $failure['install_step'],
                errorCode: $failure['install_error'],
                message: "The PHP build installer ended without a result on node [{$node->name}].",
                previous: $exception,
                result: $result,
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
            result: $result,
        );
    }

    /** @param  list<string>  $versions */
    private function installerCommand(array $versions, RoleName $role, bool $pcov, string $fpm, bool $opentelemetry): RemoteCommand
    {
        $recipe = $this->recipe ?? new RecipeLoader()->load();
        $release = [
            'id' => self::EXPECTED_DISTRIBUTION,
            'codenames' => array_map(static fn (UbuntuRelease $release): string => $release->value, UbuntuRelease::forRole($role)),
        ];

        return SshProgramRunner::pythonCommand(
            $this->catalog->program(PhpBuildInstaller::Program),
            $this->catalog->bootstrap(),
            PhpBuildInstaller::input($recipe, $versions, 'apply', $pcov, extras: 'keep', fpm: $fpm, release: $release, opentelemetry: $opentelemetry),
        );
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
    }

    /**
     * Publishes the Orbit runtime module of one version. Installation enables the shared FPM service but never starts
     * it. The caller starts or reloads it after it publishes the pools, so a stale pool that stops FPM from starting
     * cannot block the publication that removes it. A changed runtime module reloads a running FPM only when the
     * installed configuration passes `php-fpm -t`; otherwise the pool publication reloads it.
     */
    private function profileCommand(string $version, string $profile, RoleName $role): RemoteCommand
    {
        return new RemoteCommand(
            arguments: [
                'bash',
                '-seu',
                '--',
                $version,
                $profile,
                base64_encode(new PhpFpmRuntimeIniRenderer()->render($profile)),
                ...$this->releaseArguments($role),
            ],
            input: <<<'BASH'
                version=$1
                profile=$2
                runtime_ini=$3
                shift 3
                BASH."\n".OsReleaseParserProgram::render()."\n".<<<'BASH'
                packages_changed=0
                runtime_module=orbit-runtime
                runtime_available="/etc/php/$version/mods-available/$runtime_module.ini"
                runtime_enabled="/etc/php/$version/fpm/conf.d/99-$runtime_module.ini"
                runtime_work=$(mktemp -d)
                runtime_candidate="$runtime_work/candidate.ini"
                runtime_backup="$runtime_work/previous.ini"
                printf '%s' "$runtime_ini" | base64 --decode > "$runtime_candidate"
                runtime_had_file=0
                if [ -f "$runtime_available" ]; then
                    cp -- "$runtime_available" "$runtime_backup"
                    runtime_had_file=1
                fi
                runtime_had_link=0
                if [ -L "$runtime_enabled" ]; then
                    runtime_had_link=1
                fi
                runtime_changed=$packages_changed
                restore_runtime() {
                    status=$?
                    trap - EXIT
                    if [ "$status" -ne 0 ] && [ "$runtime_changed" = 1 ]; then
                        if [ "$runtime_had_file" = 1 ]; then
                            sudo install -o root -g root -m 0644 -- "$runtime_backup" "$runtime_available"
                        else
                            sudo rm -f -- "$runtime_available"
                        fi
                        if [ "$runtime_had_link" = 0 ]; then
                            sudo rm -f -- "$runtime_enabled"
                        fi
                        if sudo systemctl is-active --quiet "php$version-fpm.service" \
                            && sudo /usr/sbin/php-fpm"$version" -t >/dev/null 2>&1
                        then
                            sudo systemctl reload-or-restart "php$version-fpm.service" || true
                        fi
                    fi
                    rm -rf -- "$runtime_work"
                    exit "$status"
                }
                trap restore_runtime EXIT
                if [ "$runtime_had_file" = 0 ] || ! cmp -s -- "$runtime_candidate" "$runtime_available"; then
                    sudo install -o root -g root -m 0644 -- "$runtime_candidate" "$runtime_available"
                    runtime_changed=1
                fi
                if [ "$runtime_had_link" = 0 ] || [ "$(readlink -f -- "$runtime_enabled")" != "$runtime_available" ]; then
                    sudo phpenmod -v "$version" -s fpm "$runtime_module"
                    runtime_changed=1
                fi
                test -L "$runtime_enabled"
                test "$(readlink -f -- "$runtime_enabled")" = "$runtime_available"
                fpm_ini=$(/usr/sbin/php-fpm"$version" -i)
                printf '%s\n' "$fpm_ini" | grep -qF -- "$runtime_enabled"
                while IFS= read -r runtime_line || [ -n "$runtime_line" ]; do
                    case "$runtime_line" in ''|';'*) continue ;; esac
                    runtime_key=${runtime_line%%=*}
                    runtime_key=${runtime_key%"${runtime_key##*[! ]}"}
                    runtime_value=${runtime_line#*=}
                    runtime_value=${runtime_value#"${runtime_value%%[! ]*}"}
                    if ! printf '%s\n' "$fpm_ini" | grep -qxF -- "$runtime_key => $runtime_value => $runtime_value"; then
                        printf 'PHP %s fpm does not apply %s = %s from %s; an unmanaged ini overrides it.\n' \
                            "$version" "$runtime_key" "$runtime_value" "$runtime_enabled" >&2
                        exit 1
                    fi
                done < "$runtime_candidate"
                if [ "$runtime_changed" = 1 ] \
                    && sudo systemctl is-active --quiet "php$version-fpm.service" \
                    && sudo /usr/sbin/php-fpm"$version" -t >/dev/null 2>&1
                then
                    sudo systemctl reload-or-restart "php$version-fpm.service"
                fi
                trap - EXIT
                rm -rf -- "$runtime_work"
                sudo systemctl enable "php$version-fpm.service"
                /usr/bin/php"$version" -v >/dev/null
                /usr/sbin/php-fpm"$version" -v >/dev/null
                fpm_modules=$(/usr/sbin/php-fpm"$version" -m | tr '[:upper:]' '[:lower:]')
                if printf '%s\n' "$fpm_modules" | grep -qxF pcov; then
                    printf 'PHP %s FPM loads PCOV.\n' "$version" >&2
                    exit 1
                fi
                sudo systemctl is-enabled --quiet "php$version-fpm.service"
                BASH,
        );
    }

    /** @return list<string> */
    private function releaseArguments(RoleName $role): array
    {
        return [
            self::EXPECTED_DISTRIBUTION,
            UbuntuRelease::unsupportedText(),
            (string) count(UbuntuRelease::forRole($role)),
            ...array_map(
                static fn (UbuntuRelease $release): string => $release->value,
                UbuntuRelease::forRole($role),
            ),
        ];
    }
}
