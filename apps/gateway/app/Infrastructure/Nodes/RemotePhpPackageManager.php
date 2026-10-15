<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\UbuntuRelease;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\AppProd\ProductionSshExecutor;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Installs the recipe's exact PHP build: the pinned Sury `.deb` files of each selected version, checked by digest.
 * The recipe is the only list of PHP packages and loaded extensions. See ADR 0215.
 */
final readonly class RemotePhpPackageManager
{
    private const string EXPECTED_DISTRIBUTION = 'ubuntu';

    /** Downloads wait here, named `<package>_<sha256>.deb`, until the install step installs and deletes them. */
    public const string CACHE_DIRECTORY = '/var/cache/orbit/php-packages';

    /** The Sury apt source that earlier releases wrote. Its index floated PHP to Sury's newest build. */
    public const string RETIRED_SOURCE_PATH = '/etc/apt/sources.list.d/orbit-php.sources';

    public const string RETIRED_KEYRING_PATH = '/usr/share/keyrings/orbit-sury-php.gpg';

    /** The line the fetch program prints when every package is already at its recipe version. */
    private const string PackagesCurrent = 'orbit-php-packages: current';

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

    public function __construct(private ?MachineRecipe $recipe = null) {}

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

    /** @param Collection<int, string> $versions */
    public function installPackagesOnlyForAppProd(Node $node, Collection $versions, ProductionSshExecutor $ssh): void
    {
        if ($versions->isEmpty()) {
            return;
        }

        $pins = $this->pinsByVersion(array_values($versions->all()), self::APP_PROD_FAILURE_CONTRACT);
        $this->fetch($node, $pins, $ssh, self::APP_PROD_FAILURE_CONTRACT, RoleName::AppProd);

        foreach ($pins as $version => $rows) {
            $ssh->execute(
                $node,
                $this->packagesOnlyCommand($version, $rows),
                step: self::APP_PROD_FAILURE_CONTRACT['install_step'],
                errorCode: self::APP_PROD_FAILURE_CONTRACT['install_error'],
            );
        }
    }

    /**
     * The programs that install the `app-dev` PHP build of `$versions` on an `app-dev` Node, as
     * {@see self::installForAppDev()} runs them: the fetch first, then one install for each version.
     *
     * @param  list<string>  $versions
     * @return array<string, RemoteCommand> keyed `source` and the version
     */
    public function appDevPrograms(array $versions): array
    {
        $pins = $this->pinsByVersion($versions, self::APP_DEV_FAILURE_CONTRACT);
        $programs = ['source' => $this->fetchCommand($pins, RoleName::AppDev)];
        foreach ($pins as $version => $rows) {
            $programs[$version] = $this->profileCommand($version, 'app-dev', $rows, RoleName::AppDev);
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

        $pins = $this->pinsByVersion(array_values($versions->all()), $failure);
        $this->fetch($node, $pins, $ssh, $failure, $role);

        foreach ($pins as $version => $rows) {
            $ssh->execute(
                $node,
                $this->profileCommand($version, $profile, $rows, $role),
                step: $failure['install_step'],
                errorCode: $failure['install_error'],
            );
        }
    }

    /**
     * One row per package and architecture: `name|version|architecture|url|sha256`. The Node keeps the rows of its
     * own architecture.
     *
     * @param  list<string>  $versions
     * @param  array{source_step: string, source_error: string, install_step: string, install_error: string}  $failure
     * @return array<string, list<string>>
     */
    private function pinsByVersion(array $versions, array $failure): array
    {
        $recipe = $this->recipe ?? new RecipeLoader()->load();
        $pins = [];
        foreach (array_values(array_unique($versions)) as $version) {
            $build = $recipe->php[$version] ?? throw new RuntimeConvergenceException(
                step: $failure['source_step'],
                errorCode: $failure['source_error'],
                message: "The machine software recipe has no PHP {$version} build.",
            );
            $rows = [];
            foreach ($build->packages as $name) {
                $pin = $recipe->tools[$name];
                foreach ($pin->artifacts as $architecture => $artifact) {
                    if ($artifact->algorithm !== 'sha256' || preg_match('/[|\s]/', $name.$pin->version.$architecture.$artifact->location) === 1) {
                        throw new RuntimeConvergenceException(
                            step: $failure['source_step'],
                            errorCode: $failure['source_error'],
                            message: "The recipe pin of [{$name}] cannot be passed to the Node.",
                        );
                    }
                    $rows[] = implode('|', [$name, $pin->version, $architecture, $artifact->location, $artifact->digest]);
                }
            }
            $pins[$version] = $rows;
        }

        return $pins;
    }

    /**
     * Downloads every pinned file that is not installed at its version, unless all are: then it downloads nothing.
     * It refuses another PHP apt source and deletes Orbit's retired Sury source first.
     *
     * @param  array<string, list<string>>  $pins
     * @param  array{source_step: string, source_error: string, install_step: string, install_error: string}  $failure
     */
    private function fetch(
        Node $node,
        array $pins,
        DevelopmentSshExecutor|ProductionSshExecutor $ssh,
        array $failure,
        RoleName $role,
    ): void {
        $result = $ssh->execute(
            $node,
            $this->fetchCommand($pins, $role),
            step: $failure['source_step'],
            errorCode: $failure['source_error'],
        );
        if (in_array(self::PackagesCurrent, explode("\n", $result->stdout), true)) {
            Log::info('PHP packages match the recipe; skipped the package download.', ['node' => $node->name, 'versions' => array_keys($pins)]);
        }
    }

    /** @param array<string, list<string>> $pins */
    private function fetchCommand(array $pins, RoleName $role): RemoteCommand
    {
        $rows = array_values(array_unique(array_merge(...array_values($pins))));

        return new RemoteCommand(
            arguments: [
                'bash',
                '-seu',
                '--',
                self::CACHE_DIRECTORY,
                self::RETIRED_SOURCE_PATH,
                self::RETIRED_KEYRING_PATH,
                ...$this->releaseArguments($role),
                ...$rows,
            ],
            input: <<<'BASH'
                cache_directory=$1
                retired_source=$2
                retired_keyring=$3
                shift 3
                BASH."\n".OsReleaseParserProgram::render()."\n".self::rowsProgram()."\n".<<<'BASH'
                for configured_source in \
                    /etc/apt/sources.list \
                    /etc/apt/sources.list.d/*.list \
                    /etc/apt/sources.list.d/*.sources
                do
                    if [ ! -f "$configured_source" ] || [ "$configured_source" = "$retired_source" ]; then
                        continue
                    fi

                    if sudo grep -Eiq \
                        'ppa\.launchpadcontent\.net/ondrej/php|packages\.sury\.org/php|ondrej/php' \
                        -- "$configured_source"
                    then
                        printf '%s\n' 'A conflicting PHP package source is configured.' >&2
                        exit 1
                    fi
                done

                # The retired Sury source let apt move PHP to Sury's newest build. The recipe's files replace it.
                for retired_path in "$retired_source" "$retired_keyring"; do
                    if [ -e "$retired_path" ] || [ -L "$retired_path" ]; then
                        sudo rm -f -- "$retired_path"
                    fi
                done

                if [ "${#pending_rows[@]}" -eq 0 ]; then
                    printf '%s\n' 'orbit-php-packages: current'
                    exit 0
                fi

                umask 077
                work_directory=$(mktemp -d)
                trap 'rm -rf -- "$work_directory"' EXIT
                sudo install -d -o root -g root -m 0755 -- "$cache_directory"
                for row in "${pending_rows[@]}"; do
                    IFS='|' read -r name pinned architecture url sha256 <<< "$row"
                    cached="$cache_directory/${name}_${sha256}.deb"
                    if [ -f "$cached" ] && printf '%s  %s\n' "$sha256" "$cached" | sha256sum --check --status; then
                        continue
                    fi
                    candidate="$work_directory/candidate.deb"
                    rm -f -- "$candidate"
                    if ! curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --tlsv1.2 \
                        --output "$candidate" -- "$url"
                    then
                        printf 'The pinned PHP package %s %s cannot be downloaded. Sury may have published a newer build; update the recipe.\n' \
                            "$name" "$pinned" >&2
                        exit 1
                    fi
                    if ! printf '%s  %s\n' "$sha256" "$candidate" | sha256sum --check --status; then
                        printf 'The PHP package %s does not match its recipe SHA-256 digest.\n' "$name" >&2
                        exit 1
                    fi
                    fields=$(dpkg-deb --field "$candidate" Package Version Architecture)
                    if [ "$fields" != "$(printf 'Package: %s\nVersion: %s\nArchitecture: %s' "$name" "$pinned" "$architecture")" ] \
                        && [ "$fields" != "$(printf 'Package: %s\nVersion: %s\nArchitecture: all' "$name" "$pinned")" ]
                    then
                        printf 'The PHP package %s does not carry its recipe name, version, and architecture.\n' "$name" >&2
                        exit 1
                    fi
                    sudo install -o root -g root -m 0644 -- "$candidate" "$cached"
                done
                BASH,
        );
    }

    /**
     * Installation enables the shared FPM service but never starts it. The caller starts or reloads
     * it after it publishes the pools, so a stale pool that stops FPM from starting cannot block the
     * publication that removes it. A changed package or runtime module reloads a running FPM only when the
     * installed configuration passes `php-fpm -t`; otherwise the pool publication reloads it.
     *
     * @param  list<string>  $rows
     */
    private function profileCommand(string $version, string $profile, array $rows, RoleName $role): RemoteCommand
    {
        return new RemoteCommand(
            arguments: [
                'bash',
                '-seu',
                '--',
                $version,
                $profile,
                base64_encode(new PhpFpmRuntimeIniRenderer()->render($profile)),
                $this->expectedModules($version),
                self::CACHE_DIRECTORY,
                ...$this->releaseArguments($role),
                ...$rows,
            ],
            input: <<<'BASH'
                version=$1
                profile=$2
                runtime_ini=$3
                expected_modules=$4
                cache_directory=$5
                shift 5
                BASH."\n".OsReleaseParserProgram::render()."\n".self::rowsProgram()."\n".self::installProgram()."\n".<<<'BASH'
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
                fpm_pcov_before=$(readlink -f -- /etc/php/"$version"/fpm/conf.d/*-pcov.ini 2>/dev/null || true)
                BASH."\n".self::pcovProgram()."\n".<<<'BASH'
                fpm_pcov_after=$(readlink -f -- /etc/php/"$version"/fpm/conf.d/*-pcov.ini 2>/dev/null || true)
                if [ "$fpm_pcov_before" != "$fpm_pcov_after" ]; then
                    runtime_changed=1
                fi
                if [ "$runtime_changed" = 1 ] \
                    && sudo systemctl is-active --quiet "php$version-fpm.service" \
                    && sudo /usr/sbin/php-fpm"$version" -t >/dev/null 2>&1
                then
                    sudo systemctl reload-or-restart "php$version-fpm.service"
                fi
                trap - EXIT
                rm -rf -- "$runtime_work"
                sudo systemctl enable "php$version-fpm.service"
                BASH."\n".self::verifyProgram()."\n".<<<'BASH'
                sudo systemctl is-enabled --quiet "php$version-fpm.service"
                BASH,
        );
    }

    /**
     * Installs the build for a dedicated production master without touching the shared service.
     *
     * @param  list<string>  $rows
     */
    private function packagesOnlyCommand(string $version, array $rows): RemoteCommand
    {
        return new RemoteCommand(
            arguments: [
                'bash',
                '-seu',
                '--',
                $version,
                $this->expectedModules($version),
                self::CACHE_DIRECTORY,
                ...$this->releaseArguments(RoleName::AppProd),
                ...$rows,
            ],
            input: <<<'BASH'
                version=$1
                expected_modules=$2
                cache_directory=$3
                shift 3
                BASH."\n".OsReleaseParserProgram::render()."\n".self::rowsProgram()."\n".self::installProgram()."\n"
                .self::pcovProgram()."\n".self::verifyProgram(),
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

    /** The recipe's CLI extensions, one per line, base64 encoded. */
    private function expectedModules(string $version): string
    {
        $recipe = $this->recipe ?? new RecipeLoader()->load();

        return base64_encode(implode("\n", $recipe->php($version)->extensions));
    }

    /**
     * Reads the pin rows left in `$@`. It keeps the rows of this Node's architecture, refuses a package that has
     * none, and lists in `pending_rows` the packages that are not installed at their recipe version.
     */
    private static function rowsProgram(): string
    {
        return <<<'BASH'
            node_architecture=$(dpkg --print-architecture)
            declare -A pinned_names=()
            declare -A architecture_names=()
            node_rows=()
            pending_rows=()
            for row in "$@"; do
                IFS='|' read -r name pinned architecture url sha256 <<< "$row"
                if ! [[ "$name" =~ ^[a-z0-9][a-z0-9.+-]*$ ]] || ! [[ "$sha256" =~ ^[0-9a-f]{64}$ ]] || [ "${url#https://}" = "$url" ]; then
                    printf 'An invalid PHP package pin was passed: %s\n' "$name" >&2
                    exit 1
                fi
                pinned_names[$name]=1
                if [ "$architecture" != "$node_architecture" ]; then
                    continue
                fi
                architecture_names[$name]=1
                node_rows+=("$row")
                installed=$(dpkg-query -W -f='${Status} ${Version}' -- "$name" 2>/dev/null || true)
                if [ "$installed" != "install ok installed $pinned" ]; then
                    pending_rows+=("$row")
                fi
            done
            for name in "${!pinned_names[@]}"; do
                if [ -z "${architecture_names[$name]:-}" ]; then
                    printf 'The recipe has no %s package for architecture %s.\n' "$name" "$node_architecture" >&2
                    exit 1
                fi
            done
            BASH;
    }

    /**
     * Installs the downloaded files of the pending rows with `--allow-downgrades`, so an older or a newer build
     * becomes the recipe build. Package hooks cannot start or restart a service. Sets `packages_changed`.
     */
    private static function installProgram(): string
    {
        return <<<'BASH'
            packages_changed=0
            install_files=()
            for row in "${pending_rows[@]}"; do
                IFS='|' read -r name pinned architecture url sha256 <<< "$row"
                cached="$cache_directory/${name}_${sha256}.deb"
                if ! printf '%s  %s\n' "$sha256" "$cached" | sha256sum --check --status; then
                    printf 'The downloaded PHP package %s is missing or does not match its digest.\n' "$name" >&2
                    exit 1
                fi
                install_files+=("$cached")
            done

            if [ "${#install_files[@]}" -gt 0 ]; then
                policy_path=/usr/sbin/policy-rc.d
                policy_work=$(mktemp -d)
                policy_backup="$policy_work/policy-rc.d"
                policy_had_file=0
                if [ -e "$policy_path" ] || [ -L "$policy_path" ]; then
                    test -f "$policy_path"
                    test ! -L "$policy_path"
                    cp -a -- "$policy_path" "$policy_backup"
                    policy_had_file=1
                fi
                restore_policy() {
                    status=$?
                    trap - EXIT
                    if [ "$policy_had_file" = 1 ]; then
                        sudo cp -a -- "$policy_backup" "$policy_path"
                    else
                        sudo rm -f -- "$policy_path"
                    fi
                    rm -rf -- "$policy_work"
                    return "$status"
                }
                trap restore_policy EXIT
                printf '#!/bin/sh\nexit 101\n' > "$policy_work/policy-rc.d"
                sudo install -o root -g root -m 0755 -- "$policy_work/policy-rc.d" "$policy_path"
                sudo env DEBIAN_FRONTEND=noninteractive \
                    DEB_SYSTEMD_INVOKE=0 \
                    apt-get -o DPkg::Lock::Timeout=300 -o Dpkg::Options::=--force-confold install \
                    --yes --no-install-recommends --allow-downgrades -- \
                    "${install_files[@]}"
                restore_policy
                sudo rm -f -- "${install_files[@]}"
                packages_changed=1
            fi

            for row in "${node_rows[@]}"; do
                IFS='|' read -r name pinned architecture url sha256 <<< "$row"
                if [ "$(dpkg-query -W -f='${Status} ${Version}' -- "$name")" != "install ok installed $pinned" ]; then
                    printf 'The PHP package %s is not at its recipe version %s.\n' "$name" "$pinned" >&2
                    exit 1
                fi
            done
            BASH;
    }

    /** PCOV loads in the CLI on every role and never in PHP-FPM. */
    private static function pcovProgram(): string
    {
        return <<<'BASH'
            sudo phpenmod -v "$version" -s cli pcov
            sudo phpdismod -v "$version" -s fpm pcov
            BASH;
    }

    /** The CLI loads exactly the recipe's extensions. PHP-FPM loads the required ones and never PCOV. */
    private static function verifyProgram(): string
    {
        return <<<'BASH'
            /usr/bin/php"$version" -v >/dev/null
            /usr/sbin/php-fpm"$version" -v >/dev/null
            expected_cli=$(printf '%s' "$expected_modules" | base64 --decode)
            cli_modules=$(/usr/bin/php"$version" -m | sed -e '/^\[/d' -e '/^$/d' | tr '[:upper:]' '[:lower:]' | LC_ALL=C sort -u)
            if [ "$cli_modules" != "$expected_cli" ]; then
                missing=$(LC_ALL=C comm -23 <(printf '%s\n' "$expected_cli") <(printf '%s\n' "$cli_modules") | paste -sd ' ' -)
                extra=$(LC_ALL=C comm -13 <(printf '%s\n' "$expected_cli") <(printf '%s\n' "$cli_modules") | paste -sd ' ' -)
                printf 'PHP %s CLI modules differ from the recipe. Missing: [%s]. Extra: [%s].\n' "$version" "$missing" "$extra" >&2
                exit 1
            fi
            fpm_modules=$(/usr/sbin/php-fpm"$version" -m | tr '[:upper:]' '[:lower:]')
            for module in bcmath curl gd imagick intl mbstring mysqli pdo_mysql pdo_pgsql redis pdo_sqlite simplexml xml zip; do
                printf '%s\n' "$fpm_modules" | grep -qxF "$module"
            done
            if printf '%s\n' "$fpm_modules" | grep -qxF pcov; then
                printf 'PHP %s FPM loads PCOV.\n' "$version" >&2
                exit 1
            fi
            BASH;
    }
}
