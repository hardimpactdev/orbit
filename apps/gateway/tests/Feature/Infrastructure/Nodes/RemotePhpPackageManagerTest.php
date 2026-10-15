<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\UbuntuRelease;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\AppProd\ProductionSshExecutor;
use App\Infrastructure\MachineSoftware\ArtifactPin;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\PhpBuild;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\MachineSoftware\ToolPin;
use App\Infrastructure\Nodes\PhpFpmRuntimeIniRenderer;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\HostBinary;

it('rejects an unsupported app-prod operating system before package inspection or installation', function (): void {
    $transport = new AppDevFakeSshExecutor;
    new RemotePhpPackageManager()->installForAppProd(
        php_package_node(RoleName::AppProd),
        collect(['8.5']),
        php_package_app_prod_ssh($transport),
    );
    $root = sys_get_temp_dir().'/orbit-php-prod-'.bin2hex(random_bytes(6));
    try {
        mkdir($root.'/bin', 0777, true);
        mkdir($root.'/etc', 0777, true);
        file_put_contents($root.'/etc/os-release', "ID=ubuntu\nVERSION_CODENAME=unsupported\n");
        $log = $root.'/calls.log';
        foreach (['dpkg-query', 'apt-get'] as $tool) {
            file_put_contents(
                $root.'/bin/'.$tool,
                "#!/usr/bin/env bash\nprintf '%s\\n' {$tool} >> ".escapeshellarg($log)."\nexit 1\n",
            );
            chmod($root.'/bin/'.$tool, 0755);
        }
        $script = str_replace('/etc/os-release', $root.'/etc/os-release', $transport->commands[1]->input ?? '');
        $process = new Process(['bash', '-seu', '--', ...array_slice($transport->commands[1]->arguments, 3)], $root, [
            'PATH' => $root.'/bin:'.getenv('PATH'),
        ]);
        $process->setInput($script);
        $process->run();
        expect($process->getExitCode())
            ->not->toBe(0)->and($process->getErrorOutput())->toBe(
                UbuntuRelease::unsupportedText('ubuntu', 'unsupported')."\n",
            )->and(is_file($log) ? file_get_contents($log) : '')
            ->not->toContain('dpkg-query', 'apt-get');
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('does not treat package arguments as allowed release codenames', function (): void {
    $transport = new AppDevFakeSshExecutor;

    new RemotePhpPackageManager()->installForAppDev(
        php_package_node(RoleName::AppDev),
        collect(['8.5']),
        php_package_app_dev_ssh($transport),
    );

    foreach ([$transport->commands[0], $transport->commands[1]] as $command) {
        $script = $command->input ?? '';
        $start = mb_strpos($script, 'expected_id=$1');
        $end = mb_strpos($script, 'shift "$allowed_count"') + mb_strlen('shift "$allowed_count"');

        expect($start)->toBeInt()->and($end)->toBeInt();

        $argumentBoundary = substr($script, (int) $start, (int) $end - (int) $start);
        $argumentBoundary .= <<<'BASH'

            selected_codename=''
            for allowed_codename in "${allowed_codenames[@]}"; do
                if [ "bookworm" = "$allowed_codename" ]; then selected_codename=$allowed_codename; fi
            done
            printf '%s\n' "$selected_codename"
            BASH;

        $argumentOffset = str_contains($script, 'version=$1') ? 6 : 3;
        $arguments = array_slice($command->arguments, $argumentOffset, 3);
        $arguments[2] = '1';
        $process = new Process(['bash', '-seu', '--', ...$arguments, 'resolute', 'bookworm']);
        $process->setInput($argumentBoundary);
        $process->run();

        expect($process->getExitCode())->toBe(0)->and($process->getOutput())->toBe("\n");
    }
});

it('does not execute a malicious os-release payload in either remote script', function (): void {
    $transport = new AppDevFakeSshExecutor;
    new RemotePhpPackageManager()->installForAppDev(
        php_package_node(RoleName::AppDev),
        collect(['8.5']),
        php_package_app_dev_ssh($transport),
    );

    $root = sys_get_temp_dir().'/orbit-php-os-release-payload-'.bin2hex(random_bytes(6));
    try {
        mkdir($root.'/etc', 0777, true);
        mkdir($root.'/bin', 0777, true);
        $marker = $root.'/executed';
        $osRelease = $root.'/etc/os-release';
        file_put_contents(
            $osRelease,
            'ID=$(touch '.escapeshellarg($marker).")\nVERSION_CODENAME=\$(touch ".escapeshellarg($marker).")\n",
        );
        file_put_contents(
            $root.'/bin/dpkg-query',
            "#!/usr/bin/env bash\ntouch ".escapeshellarg($root.'/package-mutation')."\nexit 1\n",
        );
        chmod($root.'/bin/dpkg-query', 0755);
        file_put_contents($root.'/bin/dpkg', "#!/usr/bin/env bash\nprintf 'amd64\\n'\n");
        chmod($root.'/bin/dpkg', 0755);

        foreach ([0, 1] as $commandIndex) {
            $script = str_replace('/etc/os-release', $osRelease, $transport->commands[$commandIndex]->input ?? '');
            $arguments = array_slice($transport->commands[$commandIndex]->arguments, 3);
            $process = new Process(['bash', '-seu', '--', ...$arguments], $root, [
                'PATH' => $root.'/bin:'.getenv('PATH'),
            ]);
            $process->setInput($script);
            $process->run();

            expect($process->getExitCode())
                ->not
                ->toBe(0)
                ->and(is_file($marker))
                ->toBeFalse()
                ->and(is_file($root.'/package-mutation'))
                ->toBeFalse();
        }
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('rejects unsupported release metadata before mutation', function (string $osRelease): void {
    $transport = new AppDevFakeSshExecutor;
    new RemotePhpPackageManager()->installForAppDev(
        php_package_node(RoleName::AppDev),
        collect(['8.5']),
        php_package_app_dev_ssh($transport),
    );
    $root = sys_get_temp_dir().'/orbit-php-os-release-quotes-'.bin2hex(random_bytes(6));
    try {
        mkdir($root.'/etc/apt/sources.list.d', 0777, true);
        mkdir($root.'/bin', 0777, true);
        file_put_contents($root.'/etc/os-release', $osRelease);
        file_put_contents($root.'/bin/dpkg-query', "#!/usr/bin/env bash\nexit 1\n");
        chmod($root.'/bin/dpkg-query', 0755);
        file_put_contents($root.'/bin/dpkg', "#!/usr/bin/env bash\nprintf 'amd64\\n'\n");
        chmod($root.'/bin/dpkg', 0755);
        foreach ([0, 1] as $commandIndex) {
            $script = str_replace(
                ['/etc/os-release', '/etc/apt'],
                [$root.'/etc/os-release', $root.'/etc/apt'],
                $transport->commands[$commandIndex]->input ?? '',
            );
            $arguments = array_map(
                static fn (string $argument): string => str_replace(
                    ['/etc/os-release', '/etc/apt'],
                    [$root.'/etc/os-release', $root.'/etc/apt'],
                    $argument,
                ),
                array_slice($transport->commands[$commandIndex]->arguments, 3),
            );
            $process = new Process(['bash', '-seu', '--', ...$arguments], $root, [
                'PATH' => $root.'/bin:'.getenv('PATH'),
            ]);
            $process->setInput($script);
            $process->run();
            expect($process->getExitCode())
                ->not
                ->toBe(0)
                ->and($process->getErrorOutput())
                ->toBe(UbuntuRelease::unsupportedText('ubuntu', 'unsupported')."\n");
        }
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
})->with([
    'bare values' => ["ID=ubuntu\nVERSION_CODENAME=unsupported\n"],
    'paired double quotes' => ["ID=\"ubuntu\"\nVERSION_CODENAME=\"unsupported\"\n"],
    'paired single quotes' => ["ID='ubuntu'\nVERSION_CODENAME='unsupported'\n"],
    'mixed paired quotes' => ["ID=\"ubuntu\"\nVERSION_CODENAME='unsupported'\n"],
    'final line without newline' => ["ID=ubuntu\nVERSION_CODENAME=unsupported"],
]);

it('rejects invalid quoted release metadata in both remote scripts', function (string $id, string $codename): void {
    $transport = new AppDevFakeSshExecutor;
    new RemotePhpPackageManager()->installForAppDev(
        php_package_node(RoleName::AppDev),
        collect(['8.5']),
        php_package_app_dev_ssh($transport),
    );
    $root = sys_get_temp_dir().'/orbit-php-os-release-invalid-quotes-'.bin2hex(random_bytes(6));
    $requirement = UbuntuRelease::unsupportedText();
    try {
        mkdir($root.'/etc/apt/sources.list.d', 0777, true);
        mkdir($root.'/usr/share/keyrings', 0777, true);
        mkdir($root.'/bin', 0777, true);
        file_put_contents($root.'/etc/os-release', "ID={$id}\nVERSION_CODENAME={$codename}\n");
        file_put_contents(
            $root.'/bin/dpkg-query',
            "#!/usr/bin/env bash\ntouch ".escapeshellarg($root.'/package-mutation')."\nexit 1\n",
        );
        file_put_contents(
            $root.'/bin/install',
            "#!/usr/bin/env bash\ntouch ".escapeshellarg($root.'/source-mutation')."\nexit 1\n",
        );
        chmod($root.'/bin/dpkg-query', 0755);
        chmod($root.'/bin/install', 0755);

        foreach ([0, 1] as $commandIndex) {
            $script = str_replace(
                ['/etc/os-release', '/etc/apt', '/usr/share/keyrings'],
                [$root.'/etc/os-release', $root.'/etc/apt', $root.'/usr/share/keyrings'],
                $transport->commands[$commandIndex]->input ?? '',
            );
            $arguments = array_map(
                static fn (string $argument): string => str_replace(
                    ['/etc/os-release', '/etc/apt', '/usr/share/keyrings'],
                    [$root.'/etc/os-release', $root.'/etc/apt', $root.'/usr/share/keyrings'],
                    $argument,
                ),
                array_slice($transport->commands[$commandIndex]->arguments, 3),
            );
            $process = new Process(['bash', '-seu', '--', ...$arguments], $root, [
                'PATH' => $root.'/bin:'.getenv('PATH'),
            ]);
            $process->setInput($script);
            $process->run();

            expect($process->getExitCode())
                ->not
                ->toBe(0)
                ->and(trim($process->getErrorOutput()))
                ->toBe($requirement)
                ->and($process->getOutput())
                ->toBe('')
                ->and(is_file($root.'/package-mutation'))
                ->toBeFalse()
                ->and(is_file($root.'/source-mutation'))
                ->toBeFalse();
        }
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
})->with([
    'unclosed double quote in ID' => ['"ubuntu', 'resolute'],
    'unclosed single quote in ID' => ["'ubuntu", 'resolute'],
    'mismatched quote in ID' => ['"ubuntu\'', 'resolute'],
    'unclosed double quote in VERSION_CODENAME' => ['ubuntu', '"resolute'],
    'unclosed single quote in VERSION_CODENAME' => ['ubuntu', "'resolute"],
    'mismatched quote in VERSION_CODENAME' => ['ubuntu', '"resolute\''],
]);

it('loads PCOV in the CLI and never in PHP-FPM and verifies both SAPIs', function (): void {
    $transport = new AppDevFakeSshExecutor;

    new RemotePhpPackageManager()->installForAppDev(
        php_package_node(RoleName::AppDev),
        collect(['8.5']),
        php_package_app_dev_ssh($transport),
    );

    $script = $transport->commands[1]->input ?? '';

    expect($script)
        ->toContain(
            'phpenmod -v "$version" -s cli pcov',
            'phpdismod -v "$version" -s fpm pcov',
            'php"$version" -m',
            'php-fpm"$version" -m',
            'systemctl enable "php$version-fpm.service"',
            'systemctl is-enabled --quiet "php$version-fpm.service"',
        )
        ->not->toContain('xdebug')
        ->not->toContain('opentelemetry')
        ->not->toContain('enable --now');
});

it('publishes and verifies the OPcache runtime module per profile ahead of explicit service activation', function (
    string $role,
    string $profile,
): void {
    $transport = new AppDevFakeSshExecutor;
    $node = php_package_node($role === 'app-dev' ? RoleName::AppDev : RoleName::AppProd);
    $manager = new RemotePhpPackageManager;

    if ($role === 'app-dev') {
        $manager->installForAppDev($node, collect(['8.5']), php_package_app_dev_ssh($transport));
    } else {
        $manager->installForAppProd($node, collect(['8.5']), php_package_app_prod_ssh($transport));
    }

    $arguments = $transport->commands[1]->arguments;
    $script = $transport->commands[1]->input ?? '';
    $decoded = base64_decode($arguments[5], strict: true);

    expect($arguments[3])
        ->toBe('8.5')
        ->and($arguments[4])
        ->toBe($profile)
        ->and($decoded)
        ->toBe(new PhpFpmRuntimeIniRenderer()->render($profile))
        ->and($script)
        ->toContain(
            'runtime_ini=$3',
            'runtime_available="/etc/php/$version/mods-available/$runtime_module.ini"',
            'runtime_enabled="/etc/php/$version/fpm/conf.d/99-$runtime_module.ini"',
            'sudo install -o root -g root -m 0644 -- "$runtime_candidate" "$runtime_available"',
            'sudo phpenmod -v "$version" -s fpm "$runtime_module"',
            'test "$(readlink -f -- "$runtime_enabled")" = "$runtime_available"',
            'printf \'%s\\n\' "$fpm_ini" | grep -qxF -- "$runtime_key => $runtime_value => $runtime_value"',
            'trap restore_runtime EXIT',
            'if [ "$fpm_pcov_before" != "$fpm_pcov_after" ]; then',
            'if [ "$runtime_changed" = 1 ] \\',
            '&& sudo systemctl is-active --quiet "php$version-fpm.service" \\',
            '&& sudo /usr/sbin/php-fpm"$version" -t >/dev/null 2>&1',
        )
        ->and(mb_strpos($script, 'sudo systemctl reload-or-restart "php$version-fpm.service"'))
        ->toBeLessThan((int) mb_strpos($script, 'sudo systemctl enable "php$version-fpm.service"'))
        ->and(substr_count($script, 'sudo /usr/sbin/php-fpm"$version" -t >/dev/null 2>&1'))
        ->toBe(2, 'Both the runtime reload and its restore reload require valid installed pools.');
})->with([
    'app-dev node' => ['app-dev', 'app-dev'],
    'app-prod node' => ['app-prod', 'app-prod'],
]);

function php_runtime_block(string $script, string $root): string
{
    $start = (int) mb_strpos($script, 'runtime_module=orbit-runtime');
    $end = (int) mb_strpos($script, 'sudo systemctl enable "php$version-fpm.service"');
    $block = substr($script, $start, $end - $start);
    $block = str_replace(
        [
            'sudo phpenmod -v "$version" -s cli pcov',
            'sudo phpdismod -v "$version" -s fpm pcov',
            'sudo phpenmod',
            'sudo install -o root -g root',
            'sudo systemctl',
            'sudo rm -f',
            'sudo /usr/sbin/php-fpm"$version"',
            '/usr/sbin/php-fpm"$version"',
        ],
        ['true', 'true', 'phpenmod', 'install', 'systemctl', 'rm -f', 'php-fpm"$version"', 'php-fpm"$version"'],
        $block,
    );

    return str_replace('/etc/php/', $root.'/etc/php/', $block);
}

/** @param array<string, string> $binaries */
function php_runtime_fixture(string $root, array $binaries): void
{
    mkdir($root.'/bin', 0777, true);
    mkdir($root.'/etc/php/8.5/mods-available', 0777, true);
    mkdir($root.'/etc/php/8.5/fpm/conf.d', 0777, true);
    $available = $root.'/etc/php/8.5/mods-available/orbit-runtime.ini';
    $enabled = $root.'/etc/php/8.5/fpm/conf.d/99-orbit-runtime.ini';
    $effective = "Additional .ini files parsed => {$enabled}\n";
    foreach (explode("\n", new PhpFpmRuntimeIniRenderer()->render('app-dev')) as $line) {
        if ($line === '' || str_starts_with($line, ';')) {
            continue;
        }
        [$key, $value] = array_map(trim(...), explode('=', $line, 2));
        $effective .= "{$key} => {$value} => {$value}\n";
    }
    $defaults = [
        'phpenmod' => "#!/usr/bin/env bash\necho \"phpenmod \$*\" >> "
                .escapeshellarg($root.'/calls')
                ."\n"
                .'case "$*" in *orbit-runtime*) ln -s '
                .escapeshellarg($available)
                .' '
                .escapeshellarg($enabled)
                .";; esac\n",
        'php-fpm8.5' => "#!/usr/bin/env bash\nprintf '%s' ".escapeshellarg($effective)."\n",
        'systemctl' => "#!/usr/bin/env bash\necho \"systemctl \$*\" >> ".escapeshellarg($root.'/calls')."\nexit 0\n",
    ];
    foreach ([...$defaults, ...$binaries] as $name => $body) {
        file_put_contents($root.'/bin/'.$name, $body);
        chmod($root.'/bin/'.$name, 0755);
    }
}

function php_runtime_run(string $block, string $root): Process
{
    $encoded = base64_encode(new PhpFpmRuntimeIniRenderer()->render('app-dev'));
    $process = new Process(['bash', '-seu', '--', '8.5', 'app-dev', $encoded], $root, [
        'PATH' => $root.'/bin:'.getenv('PATH'),
    ]);
    $process->setInput("version=\$1\nprofile=\$2\nruntime_ini=\$3\npackages_changed=0\n".$block);
    $process->run();

    return $process;
}

function php_runtime_calls(string $root): string
{
    $calls = is_file($root.'/calls') ? (string) file_get_contents($root.'/calls') : '';
    file_put_contents($root.'/calls', '');

    return $calls;
}

it('rewrites the runtime module and reloads FPM when the ini content or its enablement changed', function (): void {
    $transport = new AppDevFakeSshExecutor;
    new RemotePhpPackageManager()->installForAppDev(
        php_package_node(RoleName::AppDev),
        collect(['8.5']),
        php_package_app_dev_ssh($transport),
    );

    $root = (string) realpath(sys_get_temp_dir()).'/orbit-php-runtime-ini-'.bin2hex(random_bytes(6));
    $block = php_runtime_block($transport->commands[1]->input ?? '', $root);
    $available = $root.'/etc/php/8.5/mods-available/orbit-runtime.ini';
    $enabled = $root.'/etc/php/8.5/fpm/conf.d/99-orbit-runtime.ini';

    try {
        php_runtime_fixture($root, []);

        $first = php_runtime_run($block, $root);
        $firstCalls = php_runtime_calls($root);
        $second = php_runtime_run($block, $root);
        $secondCalls = php_runtime_calls($root);
        unlink($enabled);
        $third = php_runtime_run($block, $root);
        $thirdCalls = php_runtime_calls($root);

        expect($first->getExitCode())
            ->toBe(0, $first->getErrorOutput())
            ->and(file_get_contents($available))
            ->toBe(new PhpFpmRuntimeIniRenderer()->render('app-dev'))
            ->and(is_link($enabled))
            ->toBeTrue()
            ->and($firstCalls)
            ->toContain(
                'phpenmod -v 8.5 -s fpm orbit-runtime',
                'systemctl is-active --quiet php8.5-fpm.service',
                'systemctl reload-or-restart php8.5-fpm.service',
            )
            ->and($second->getExitCode())
            ->toBe(0, $second->getErrorOutput())
            ->and($secondCalls)
            ->not
            ->toContain('phpenmod', 'reload-or-restart')
            ->and($third->getExitCode())
            ->toBe(0, $third->getErrorOutput())
            ->and(is_link($enabled))
            ->toBeTrue()
            ->and($thirdCalls)
            ->toContain('phpenmod -v 8.5 -s fpm orbit-runtime', 'systemctl reload-or-restart php8.5-fpm.service');
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('leaves a running FPM alone when its installed pools fail validation, so a stale pool cannot stop it', function (): void {
    $transport = new AppDevFakeSshExecutor;
    new RemotePhpPackageManager()->installForAppDev(
        php_package_node(RoleName::AppDev),
        collect(['8.5']),
        php_package_app_dev_ssh($transport),
    );

    $root = (string) realpath(sys_get_temp_dir()).'/orbit-php-runtime-stale-pool-'.bin2hex(random_bytes(6));
    $block = php_runtime_block($transport->commands[1]->input ?? '', $root);
    $enabled = $root.'/etc/php/8.5/fpm/conf.d/99-orbit-runtime.ini';

    try {
        php_runtime_fixture($root, []);
        $effective = (string) shell_exec(escapeshellarg($root.'/bin/php-fpm8.5'));
        file_put_contents(
            $root.'/bin/php-fpm8.5',
            "#!/usr/bin/env bash\nif [ \"\${1:-}\" = -t ]; then\n    echo 'the chdir path does not exist' >&2\n    exit 78\nfi\nprintf '%s' "
                .escapeshellarg($effective)."\n",
        );

        $process = php_runtime_run($block, $root);
        $calls = php_runtime_calls($root);

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput())
            ->and(is_link($enabled))
            ->toBeTrue()
            ->and($calls)
            ->toContain('phpenmod -v 8.5 -s fpm orbit-runtime', 'systemctl is-active --quiet php8.5-fpm.service')
            ->not->toContain('reload-or-restart');
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('restores the previous runtime module and enablement when publication fails', function (): void {
    $transport = new AppDevFakeSshExecutor;
    new RemotePhpPackageManager()->installForAppDev(
        php_package_node(RoleName::AppDev),
        collect(['8.5']),
        php_package_app_dev_ssh($transport),
    );

    $root = (string) realpath(sys_get_temp_dir()).'/orbit-php-runtime-ini-'.bin2hex(random_bytes(6));
    $block = php_runtime_block($transport->commands[1]->input ?? '', $root);
    $available = $root.'/etc/php/8.5/mods-available/orbit-runtime.ini';
    $enabled = $root.'/etc/php/8.5/fpm/conf.d/99-orbit-runtime.ini';
    $previous = "; priority=99\nopcache.memory_consumption = 128\n";

    try {
        php_runtime_fixture($root, [
            'phpenmod' => "#!/usr/bin/env bash\necho \"phpenmod \$*\" >> ".escapeshellarg($root.'/calls')."\nexit 1\n",
        ]);
        file_put_contents($available, $previous);

        $failed = php_runtime_run($block, $root);
        $calls = php_runtime_calls($root);

        expect($failed->getExitCode())
            ->not
            ->toBe(0)
            ->and(file_get_contents($available))
            ->toBe($previous)
            ->and(file_exists($enabled))
            ->toBeFalse()
            ->and($calls)
            ->toContain('phpenmod -v 8.5 -s fpm orbit-runtime', 'systemctl reload-or-restart php8.5-fpm.service');
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('rejects a runtime module whose effective FPM values differ from the rendered ini', function (): void {
    $transport = new AppDevFakeSshExecutor;
    new RemotePhpPackageManager()->installForAppDev(
        php_package_node(RoleName::AppDev),
        collect(['8.5']),
        php_package_app_dev_ssh($transport),
    );

    $root = (string) realpath(sys_get_temp_dir()).'/orbit-php-runtime-ini-'.bin2hex(random_bytes(6));
    $block = php_runtime_block($transport->commands[1]->input ?? '', $root);
    $available = $root.'/etc/php/8.5/mods-available/orbit-runtime.ini';
    $enabled = $root.'/etc/php/8.5/fpm/conf.d/99-orbit-runtime.ini';

    try {
        php_runtime_fixture($root, [
            'php-fpm8.5' => "#!/usr/bin/env bash\nprintf 'Additional .ini files parsed => %s\\nopcache.enable => On => On\\nopcache.memory_consumption => 128 => 128\\n' "
                    .escapeshellarg($enabled)
                    ."\n",
        ]);

        $failed = php_runtime_run($block, $root);

        expect($failed->getExitCode())
            ->not
            ->toBe(0)
            ->and($failed->getErrorOutput())
            ->toContain('does not apply opcache.memory_consumption = 512')
            ->and(file_exists($available))
            ->toBeFalse()
            ->and(file_exists($enabled))
            ->toBeFalse();
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('verifies the managed absolute PHP binaries', function (): void {
    $transport = new AppDevFakeSshExecutor;

    new RemotePhpPackageManager()->installForAppDev(
        php_package_node(RoleName::AppDev),
        collect(['8.5']),
        php_package_app_dev_ssh($transport),
    );

    $script = $transport->commands[1]->input ?? '';
    $verification = substr($script, (int) mb_strpos($script, 'sudo systemctl enable "php$version-fpm.service"'));
    $root = sys_get_temp_dir().'/orbit-php-binary-verification-'.bin2hex(random_bytes(6));

    try {
        mkdir($root.'/bin', 0777, true);
        mkdir($root.'/usr/bin', 0777, true);
        mkdir($root.'/usr/sbin', 0777, true);
        file_put_contents(
            $root.'/bin/php8.5',
            "#!/usr/bin/env bash\necho shadowed >> ".escapeshellarg($root.'/calls')."\nexit 1\n",
        );
        file_put_contents(
            $root.'/bin/php-fpm8.5',
            "#!/usr/bin/env bash\necho shadowed >> ".escapeshellarg($root.'/calls')."\nexit 1\n",
        );
        $modules = ['bcmath', 'curl', 'gd', 'imagick', 'intl', 'mbstring', 'mysqli', 'pcov', 'pdo_mysql', 'pdo_pgsql', 'pdo_sqlite', 'redis', 'simplexml', 'xml', 'zip'];
        $cliModules = "#!/usr/bin/env bash\nprintf '%s\\n' '[PHP Modules]' ".implode(' ', $modules)."\n";
        $fpmModules = str_replace(' pcov', '', $cliModules);
        file_put_contents($root.'/usr/bin/php8.5', $cliModules);
        file_put_contents($root.'/usr/sbin/php-fpm8.5', $fpmModules);
        file_put_contents($root.'/bin/systemctl', "#!/usr/bin/env bash\nexit 0\n");
        foreach ([
            $root.'/bin/php8.5',
            $root.'/bin/php-fpm8.5',
            $root.'/usr/bin/php8.5',
            $root.'/usr/sbin/php-fpm8.5',
            $root.'/bin/systemctl',
        ] as $binary) {
            chmod($binary, 0755);
        }
        $verification = str_replace(
            ['sudo systemctl', '/usr/bin', '/usr/sbin'],
            ['systemctl', $root.'/usr/bin', $root.'/usr/sbin'],
            $verification,
        );
        $process = new Process(['bash', '-seu', '--', '8.5', base64_encode(implode("\n", $modules))], $root, [
            'PATH' => $root.'/bin:'.getenv('PATH'),
        ]);
        $process->setInput("version=\$1\nexpected_modules=\$2\n".$verification);
        $process->run();

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput())
            ->and(is_file($root.'/calls') ? file_get_contents($root.'/calls') : '')
            ->toBe('');
    } finally {
        new Filesystem()->deleteDirectory($root);
    }

    expect($script)
        ->toContain(
            '/usr/bin/php"$version" -v',
            '/usr/sbin/php-fpm"$version" -v',
            '/usr/bin/php"$version" -m',
            '/usr/sbin/php-fpm"$version" -m',
        )
        ->not->toContain(
            "\n                    php\"\$version\" -v",
            "\n                    php-fpm\"\$version\" -v",
            "\n                    cli_modules=\$(php\"\$version\" -m",
            "\n                    fpm_modules=\$(php-fpm\"\$version\" -m",
        );
});

it('maps source failures to the stable role-specific contract', function (string $role): void {
    $transport = new AppDevFakeSshExecutor([
        new CommandResult(1, '', UbuntuRelease::unsupportedText(), 1, false),
    ]);
    $manager = new RemotePhpPackageManager;
    $nodeRole = $role === 'app-dev' ? RoleName::AppDev : RoleName::AppProd;

    $operation = $role === 'app-dev'
        ? fn () => $manager->installForAppDev(
            php_package_node($nodeRole),
            collect(['8.5']),
            php_package_app_dev_ssh($transport),
        )
        : fn () => $manager->installForAppProd(
            php_package_node($nodeRole),
            collect(['8.5']),
            php_package_app_prod_ssh($transport),
        );

    expect($operation)->toThrow(function (RuntimeConvergenceException $exception) use ($role): void {
        expect($exception->step)
            ->toBe($role === 'app-dev' ? 'php-package-source' : 'app-prod-php-package-source')
            ->and($exception->errorCode)
            ->toBe(
                $role === 'app-dev'
                    ? 'app-dev.php_package_source_unavailable'
                    : 'app-prod.php_package_source_unavailable',
            );
    });

    expect($transport->commands)->toHaveCount(1);
})->with(['app-dev', 'app-prod']);

it('performs no remote work for an empty version collection', function (): void {
    $transport = new AppDevFakeSshExecutor;

    new RemotePhpPackageManager()->installForAppDev(
        php_package_node(RoleName::AppDev),
        collect(),
        php_package_app_dev_ssh($transport),
    );

    expect($transport->commands)->toBeEmpty();
});

it('renders syntactically valid fixed shell programs', function (): void {
    $transport = new AppDevFakeSshExecutor;

    new RemotePhpPackageManager()->installForAppDev(
        php_package_node(RoleName::AppDev),
        collect(['8.4', '8.5']),
        php_package_app_dev_ssh($transport),
    );

    foreach ($transport->commands as $command) {
        $process = new Process(['bash', '-n']);
        $process->setInput($command->input ?? '');
        $process->run();

        expect($process->isSuccessful())
            ->toBeTrue(
                $process->getErrorOutput()."\n".($command->input ?? ''),
            );
    }
});

it('fetches and installs the recipe build of each selected version', function (): void {
    $transport = new AppDevFakeSshExecutor;
    $recipe = new RecipeLoader()->load();

    new RemotePhpPackageManager()->installForAppDev(
        php_package_node(RoleName::AppDev),
        collect(['8.4']),
        php_package_app_dev_ssh($transport),
    );

    expect($transport->commands)->toHaveCount(2);
    [$fetch, $install] = $transport->commands;
    $rows = php_package_rows($recipe, '8.4');

    expect(array_slice($fetch->arguments, 0, 10))
        ->toBe([
            'bash',
            '-seu',
            '--',
            RemotePhpPackageManager::CACHE_DIRECTORY,
            RemotePhpPackageManager::RETIRED_SOURCE_PATH,
            RemotePhpPackageManager::RETIRED_KEYRING_PATH,
            'ubuntu',
            UbuntuRelease::unsupportedText(),
            '1',
            'resolute',
        ])
        ->and(array_slice($fetch->arguments, 10))->toBe($rows)
        ->and($fetch->input)->toContain(
            "selected_codename=''",
            'ppa\\.launchpadcontent\\.net/ondrej/php',
            'sudo rm -f -- "$retired_path"',
            "curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --tlsv1.2",
            'sha256sum --check --status',
            'dpkg-deb --field "$candidate" Package Version Architecture',
            'sudo install -o root -g root -m 0644 -- "$candidate" "$cached"',
        )
        ->not->toContain('apt-key', 'add-apt-repository', 'apt-cache', 'apt-get')
        ->and(array_slice($install->arguments, 0, 13))->toBe([
            'bash',
            '-seu',
            '--',
            '8.4',
            'app-dev',
            base64_encode(new PhpFpmRuntimeIniRenderer()->render('app-dev')),
            base64_encode(implode("\n", $recipe->php('8.4')->extensions)),
            RemotePhpPackageManager::CACHE_DIRECTORY,
            'ubuntu',
            UbuntuRelease::unsupportedText(),
            '1',
            'resolute',
            $rows[0],
        ])
        ->and(array_slice($install->arguments, 12))->toBe($rows)
        ->and($install->input)->toContain('--allow-downgrades', 'DEB_SYSTEMD_INVOKE=0', 'sudo rm -f -- "${install_files[@]}"')
        ->not->toContain('apt-key', 'add-apt-repository', 'apt-cache');
});

it('selects only the validated release suite for each role policy', function (): void {
    $transport = new AppDevFakeSshExecutor;

    new RemotePhpPackageManager()->installForAppDev(
        php_package_node(RoleName::AppDev),
        collect(['8.5']),
        php_package_app_dev_ssh($transport),
    );

    expect($transport->commands[0]->input)->toContain("selected_codename=''")
        ->and($transport->commands[0]->arguments)->toContain('resolute');
});

it('uses the recipe PHP set of the version on both roles', function (string $role, string $version): void {
    $transport = new AppDevFakeSshExecutor;
    $node = php_package_node($role === 'app-dev' ? RoleName::AppDev : RoleName::AppProd);
    $manager = new RemotePhpPackageManager;
    $role === 'app-dev'
        ? $manager->installForAppDev($node, collect([$version]), php_package_app_dev_ssh($transport))
        : $manager->installForAppProd($node, collect([$version]), php_package_app_prod_ssh($transport));
    $names = static fn (RemoteCommand $command): array => array_values(array_unique(array_map(
        static fn (string $row): string => explode('|', $row)[0],
        array_filter($command->arguments, static fn (string $argument): bool => substr_count($argument, '|') === 4),
    )));
    $build = new RecipeLoader()->load()->php($version);

    expect($names($transport->commands[0]))->toBe($build->packages)
        ->and($names($transport->commands[1]))->toBe($build->packages)
        ->and($build->packages)->toContain("php{$version}-pcov", "php{$version}-cli", 'php-common', 'libgd3');
})->with([
    'app-dev PHP 8.4' => ['app-dev', '8.4'],
    'app-dev PHP 8.5' => ['app-dev', '8.5'],
    'app-prod PHP 8.4' => ['app-prod', '8.4'],
    'app-prod PHP 8.5' => ['app-prod', '8.5'],
]);

it('refuses a PHP version that the recipe does not build before any remote work', function (string $role): void {
    $transport = new AppDevFakeSshExecutor;
    $node = php_package_node($role === 'app-dev' ? RoleName::AppDev : RoleName::AppProd);
    $manager = new RemotePhpPackageManager;
    $install = $role === 'app-dev'
        ? fn () => $manager->installForAppDev($node, collect(['8.6']), php_package_app_dev_ssh($transport))
        : fn () => $manager->installForAppProd($node, collect(['8.6']), php_package_app_prod_ssh($transport));

    expect($install)->toThrow(function (RuntimeConvergenceException $exception) use ($role): void {
        expect($exception->errorCode)->toBe("{$role}.php_package_source_unavailable")
            ->and($exception->getMessage())->toContain('no PHP 8.6 build');
    });
    expect($transport->commands)->toBe([]);
})->with(['app-dev', 'app-prod']);

it('downloads only the packages that are not at their recipe version and checks each one', function (
    string $case,
    int $exitCode,
    string $error,
): void {
    $transport = new AppDevFakeSshExecutor;
    $recipe = php_package_recipe();
    new RemotePhpPackageManager($recipe)->installForAppDev(php_package_node(RoleName::AppDev), collect(['8.5']), php_package_app_dev_ssh($transport));
    $root = (string) realpath(sys_get_temp_dir()).'/orbit-php-fetch-'.bin2hex(random_bytes(6));
    try {
        php_package_fixture($root, $case);
        if ($case === 'current') {
            foreach (['php-common', 'php8.5-cli'] as $name) {
                file_put_contents("{$root}/installed/{$name}", "1.0-{$name}");
            }
        }
        if ($case === 'conflicting source') {
            file_put_contents($root.'/etc/apt/sources.list.d/ondrej.list', "deb https://ppa.launchpadcontent.net/ondrej/php/ubuntu resolute main\n");
        }
        $process = php_package_run($transport->commands[0], $root, $case === 'arm64 Node' ? 'arm64' : 'amd64');
        $calls = is_file($root.'/calls') ? (string) file_get_contents($root.'/calls') : '';
        $cached = array_map('basename', glob($root.'/cache/*') ?: []);

        expect($process->getExitCode())->toBe($exitCode, $process->getErrorOutput())
            ->and($process->getErrorOutput())->toContain($error);
        match ($case) {
            'nothing installed' => expect($cached)->toBe(php_package_cached_names($recipe))
                ->and(file_exists($root.'/etc/apt/sources.list.d/orbit-php.sources'))->toBeFalse()
                ->and(file_exists($root.'/usr/share/keyrings/orbit-sury-php.gpg'))->toBeFalse()
                ->and($process->getOutput())->toBe(''),
            'current' => expect($process->getOutput())->toBe("orbit-php-packages: current\n")
                ->and($calls)->not->toContain('curl'),
            default => expect($cached)->toBe([]),
        };
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
})->with([
    'nothing installed' => ['nothing installed', 0, ''],
    'every package at its version' => ['current', 0, ''],
    'a tampered download' => ['tampered', 1, 'does not match its recipe SHA-256 digest'],
    'a package with another version inside' => ['wrong fields', 1, 'does not carry its recipe name, version, and architecture'],
    'an unpublished build' => ['unavailable', 1, 'cannot be downloaded. Sury may have published a newer build'],
    'another PHP apt source' => ['conflicting source', 1, 'A conflicting PHP package source is configured.'],
    'a Node architecture without pins' => ['arm64 Node', 1, 'package for architecture arm64.'],
]);

it('installs the downloaded build with downgrades allowed and checks the loaded extensions', function (
    string $modules,
    int $exitCode,
    string $error,
): void {
    $transport = new AppDevFakeSshExecutor;
    $recipe = php_package_recipe();
    $manager = new RemotePhpPackageManager($recipe);
    $manager->installPackagesOnlyForAppProd(php_package_node(RoleName::AppProd), collect(['8.5']), php_package_app_prod_ssh($transport));
    $root = (string) realpath(sys_get_temp_dir()).'/orbit-php-install-'.bin2hex(random_bytes(6));
    try {
        php_package_fixture($root, 'nothing installed');
        file_put_contents($root.'/installed/php8.5-cli', '0.9-older');
        expect(php_package_run($transport->commands[0], $root)->getExitCode())->toBe(0);
        file_put_contents($root.'/calls', '');
        file_put_contents($root.'/usr/bin/php8.5', "#!/usr/bin/env bash\nif [ \"\$1\" = -m ]; then printf '%s' ".escapeshellarg($modules)."; fi\n");
        file_put_contents($root.'/usr/sbin/php-fpm8.5', "#!/usr/bin/env bash\nif [ \"\$1\" = -m ]; then printf '%s\\n' bcmath curl gd imagick intl mbstring mysqli pdo_mysql pdo_pgsql redis pdo_sqlite simplexml xml zip; fi\n");
        chmod($root.'/usr/bin/php8.5', 0755);
        chmod($root.'/usr/sbin/php-fpm8.5', 0755);

        $process = php_package_run($transport->commands[1], $root);
        $calls = (string) file_get_contents($root.'/calls');

        expect($process->getExitCode())->toBe($exitCode, $process->getErrorOutput())
            ->and($process->getErrorOutput())->toContain($error)
            ->and($calls)->toContain('apt-get', '--allow-downgrades', $root.'/cache/php8.5-cli_', $root.'/cache/php-common_')
            ->toContain('phpenmod -v 8.5 -s cli pcov', 'phpdismod -v 8.5 -s fpm pcov')
            ->and(glob($root.'/cache/*'))->toBe([])
            ->and($transport->commands[1]->input)->not->toContain('orbit-runtime', 'systemctl', 'php$version-fpm.service')
            ->and(file_get_contents($root.'/installed/php8.5-cli'))->toBe('1.0-php8.5-cli')
            ->and(file_exists($root.'/usr/sbin/policy-rc.d'))->toBeFalse();
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
})->with([
    'the recipe extensions' => ["[PHP Modules]\nCore\npcov\n\n[Zend Modules]\n", 0, ''],
    'an extra extension' => ["[PHP Modules]\nCore\napcu\npcov\n", 1, 'PHP 8.5 CLI modules differ from the recipe. Missing: []. Extra: [apcu].'],
    'a missing extension' => ["[PHP Modules]\nCore\n", 1, 'Missing: [pcov]. Extra: [].'],
]);

it('retains downloaded packages when installation fails', function (): void {
    $transport = new AppDevFakeSshExecutor([
        new CommandResult(0, '', '', 1, false),
        new CommandResult(1, '', 'package install failed', 1, false),
    ]);

    expect(fn () => new RemotePhpPackageManager()->installForAppDev(
        php_package_node(RoleName::AppDev),
        collect(['8.5']),
        php_package_app_dev_ssh($transport),
    ))->toThrow(function (RuntimeConvergenceException $exception): void {
        expect($exception->step)
            ->toBe('php-fpm-install')
            ->and($exception->errorCode)
            ->toBe('app-dev.php_install_failed');
    });

    expect($transport->commands)->toHaveCount(2)
        ->and($transport->commands[1]->input)->not->toContain('apt-get remove', 'apt-get purge');
});

it('logs a skipped download and still verifies each version', function (): void {
    Log::spy();
    $transport = new AppDevFakeSshExecutor([new CommandResult(0, "orbit-php-packages: current\n", '', 1, false)]);

    new RemotePhpPackageManager()->installForAppDev(
        php_package_node(RoleName::AppDev),
        collect(['8.5']),
        php_package_app_dev_ssh($transport),
    );

    expect($transport->commands)->toHaveCount(2)
        ->and($transport->commands[1]->arguments[3])->toBe('8.5');
    Log::shouldHaveReceived('info')->once()->with('PHP packages match the recipe; skipped the package download.', Mockery::on(static fn (array $context): bool => $context['versions'] === ['8.5']));
});

function php_package_recipe(): MachineRecipe
{
    $tools = [];
    foreach (['php-common', 'php8.5-cli'] as $name) {
        $file = "{$name}_1.0_amd64.deb";
        $tools[$name] = new ToolPin('artifact', "1.0-{$name}", artifacts: [
            'amd64' => new ArtifactPin("https://packages.sury.org/php/pool/main/{$file}", 'sha256', hash('sha256', "deb:{$file}")),
        ]);
    }

    return new MachineRecipe('sha256:fixture', '20261014T000000Z', ['amd64'], $tools, [], '{}', '{}', [
        '8.5' => new PhpBuild('8.5', '8.5.11', ['php-common', 'php8.5-cli'], ['core', 'pcov']),
    ], '8.5');
}

/** @return list<string> */
function php_package_cached_names(MachineRecipe $recipe): array
{
    $names = [];
    foreach ($recipe->php('8.5')->packages as $name) {
        $names[] = $name.'_'.$recipe->tools[$name]->artifacts['amd64']->digest.'.deb';
    }
    sort($names);

    return $names;
}

/** @return list<string> */
function php_package_rows(MachineRecipe $recipe, string $version): array
{
    $rows = [];
    foreach ($recipe->php($version)->packages as $name) {
        foreach ($recipe->tools[$name]->artifacts as $architecture => $artifact) {
            $rows[] = implode('|', [$name, $recipe->tools[$name]->version, $architecture, $artifact->location, $artifact->digest]);
        }
    }

    return $rows;
}

function php_package_fixture(string $root, string $case): void
{
    foreach (['bin', 'cache', 'installed', 'etc/apt/sources.list.d', 'usr/share/keyrings', 'usr/bin', 'usr/sbin'] as $directory) {
        mkdir("{$root}/{$directory}", 0777, true);
    }
    file_put_contents($root.'/etc/os-release', "ID=ubuntu\nVERSION_CODENAME=resolute\n");
    file_put_contents($root.'/etc/apt/sources.list.d/orbit-php.sources', "URIs: https://packages.sury.org/php/\n");
    file_put_contents($root.'/usr/share/keyrings/orbit-sury-php.gpg', 'retired key');
    $calls = escapeshellarg($root.'/calls');
    $installed = escapeshellarg($root.'/installed');
    $write = static function (string $name, string $body) use ($root): void {
        file_put_contents($root.'/bin/'.$name, HostBinary::expand("#!/usr/bin/env bash\n{$body}\n"));
        chmod($root.'/bin/'.$name, 0755);
    };
    $write('sudo', 'exec "$@"');
    $write('install', 'args=(); skip=0; for arg in "$@"; do if [ "$skip" = 1 ]; then skip=0; continue; fi; case "$arg" in -o|-g) skip=1;; *) args+=("$arg");; esac; done; exec {{host:install}} "${args[@]}"');
    $write('dpkg', 'printf "%s\n" "${ORBIT_TEST_ARCHITECTURE:-amd64}"');
    $write('dpkg-query', 'name="${@: -1}"; [ -f '.$installed.'/"$name" ] || exit 1; printf "install ok installed %s" "$(cat '.$installed.'/"$name")"');
    $content = match ($case) {
        'tampered' => 'printf tampered > "$out"',
        'unavailable' => 'exit 22',
        default => 'printf "deb:%s" "${url##*/}" > "$out"',
    };
    $write('curl', 'printf "curl\n" >> '.$calls.'; out=""; url="${@: -1}"; while [ "$#" -gt 0 ]; do [ "$1" = --output ] && { out="$2"; shift 2; continue; }; shift; done; '.$content);
    $version = $case === 'wrong fields' ? '9.9' : '1.0-$name';
    $write('dpkg-deb', 'file=$(cat "$2"); file=${file#deb:}; name=${file%%_1.0_*}; printf "Package: %s\nVersion: %s\nArchitecture: amd64\n" "$name" "'.$version.'"');
    $write('apt-get', 'printf "apt-get %s\n" "$*" >> '.$calls.'; for arg in "$@"; do case "$arg" in *.deb) file=$(cat "$arg"); file=${file#deb:}; name=${file%%_1.0_*}; printf "1.0-%s" "$name" > '.$installed.'/"$name";; esac; done');
    $write('phpenmod', 'printf "phpenmod %s\n" "$*" >> '.$calls);
    $write('phpdismod', 'printf "phpdismod %s\n" "$*" >> '.$calls);
}

function php_package_run(RemoteCommand $command, string $root, string $architecture = 'amd64'): Process
{
    $paths = [
        RemotePhpPackageManager::CACHE_DIRECTORY => $root.'/cache',
        '/etc/os-release' => $root.'/etc/os-release',
        '/etc/apt' => $root.'/etc/apt',
        '/usr/share/keyrings' => $root.'/usr/share/keyrings',
        '/usr/bin/php' => $root.'/usr/bin/php',
        '/usr/sbin/' => $root.'/usr/sbin/',
    ];
    $replace = static fn (string $value): string => strtr($value, $paths);
    $process = new Process(['bash', '-seu', '--', ...array_map($replace, array_slice($command->arguments, 3))], $root, [
        'PATH' => $root.'/bin:'.getenv('PATH'),
        'ORBIT_TEST_ARCHITECTURE' => $architecture,
    ]);
    $process->setInput($replace($command->input ?? ''));
    $process->run();

    return $process;
}

function php_package_node(RoleName $role): Node
{
    $count = Node::query()->count();
    $node = Node::query()->create([
        'name' => 'php-package-node-'.str_replace(search: '-', replace: '', subject: $role->value)."-{$count}",
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.44',
        'wireguard_ip' => '10.44.0.'.(44 + $count),
        'user' => 'orbit',
    ]);
    $node->roles()->create(['role' => $role]);

    return $node->load('roles');
}

function php_package_app_dev_ssh(AppDevFakeSshExecutor $transport): DevelopmentSshExecutor
{
    return new DevelopmentSshExecutor(
        ssh: $transport,
        keys: php_package_keys(),
        knownHosts: php_package_known_hosts(),
    );
}

function php_package_app_prod_ssh(AppDevFakeSshExecutor $transport): ProductionSshExecutor
{
    return new ProductionSshExecutor(
        ssh: $transport,
        keys: php_package_keys(),
        knownHosts: php_package_known_hosts(),
    );
}

function php_package_keys(): SshKeyProvider
{
    return new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/tmp/orbit-test-key';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 TEST';
        }
    };
}

function php_package_known_hosts(): KnownHostsStore
{
    return new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/tmp/orbit-test-known-hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
}

function php_package_scripts(AppDevFakeSshExecutor $transport): string
{
    return Collection::make($transport->commands)
        ->map(static fn (RemoteCommand $command): string => $command->input ?? '')
        ->implode("\n");
}
