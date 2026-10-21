<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\UbuntuRelease;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\AppProd\ProductionSshExecutor;
use App\Infrastructure\MachineSoftware\RecipeLoader;
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

    foreach ([$transport->commands[1]] as $command) {
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

        foreach ([1] as $commandIndex) {
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
        foreach ([1] as $commandIndex) {
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

        foreach ([1] as $commandIndex) {
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

    foreach (array_slice($transport->commands, 1) as $command) {
        $process = new Process(['bash', '-n']);
        $process->setInput($command->input ?? '');
        $process->run();

        expect($process->isSuccessful())
            ->toBeTrue(
                $process->getErrorOutput()."\n".($command->input ?? ''),
            );
    }
});
it('installs through the one PHP build installer and keeps extra packages on an Instance step', function (): void {
    $transport = new AppDevFakeSshExecutor;
    $recipe = new RecipeLoader()->load();

    new RemotePhpPackageManager()->installForAppDev(php_package_node(RoleName::AppDev), collect(['8.4', '8.5']), php_package_app_dev_ssh($transport));

    expect($transport->commands)->toHaveCount(3);
    $installer = $transport->commands[0];
    $input = php_package_installer_input($installer);
    expect(array_slice($installer->arguments, 0, 3))->toBe(['python3', '-I', '-c'])
        ->and($installer->arguments[4])->toBe('php.install-build')
        ->and($input['mode'])->toBe('apply')
        ->and($input['extras'])->toBe('keep')
        ->and($input['fpm'])->toBe('shared')
        ->and($input['pcov'])->toBeTrue()
        ->and($input['opentelemetry'])->toBeTrue()
        ->and($input['mirror'])->toBe($recipe->artifactMirror)
        ->and($input['repack'])->toBe(['package' => 'dpkg-repack', 'version' => $recipe->tools['dpkg-repack']->version])
        ->and($input['release'])->toBe(['id' => 'ubuntu', 'codenames' => ['resolute']])
        ->and(array_keys($input['versions']))->toBe(['8.4', '8.5'])
        ->and($input['versions']['8.5']['extensions'])->toBe($recipe->php('8.5')->cliExtensions())
        ->and(array_values(array_unique(array_column($input['versions']['8.5']['packages'], 'name'))))->toBe($recipe->php('8.5')->packages)
        ->and($transport->commands[1]->arguments[3])->toBe('8.4')
        ->and($transport->commands[2]->arguments[3])->toBe('8.5');
    foreach ($input['versions']['8.5']['packages'] as $pin) {
        $artifact = $recipe->tools[$pin['name']]->artifacts[$pin['architecture']];
        expect($pin)->toBe(['name' => $pin['name'], 'version' => $recipe->tools[$pin['name']]->version, 'architecture' => $pin['architecture'], 'url' => $artifact->location, 'sha256' => $artifact->digest]);
    }
});

it('loads PCOV only on app-dev Nodes', function (array $roles, bool $pcov): void {
    $transport = new AppDevFakeSshExecutor;
    $node = php_package_node($roles[0]);
    foreach (array_slice($roles, 1) as $role) {
        $node->roles()->create(['role' => $role]);
    }

    new RemotePhpPackageManager()->installForAppProd($node->load('roles'), collect(['8.5']), php_package_app_prod_ssh($transport));

    expect(php_package_installer_input($transport->commands[0])['pcov'])->toBe($pcov);
    $input = php_package_installer_input($transport->commands[0]);
    expect($input['opentelemetry'])->toBe($pcov);
    expect(in_array('php8.5-opentelemetry', array_column($input['versions']['8.5']['packages'], 'name'), true))->toBe($pcov);
    expect($input['versions']['8.5']['extensions'])->not->toContain('opentelemetry');
})->with([
    'a production Node' => [[RoleName::AppProd], false],
    'a Node with both roles' => [[RoleName::AppProd, RoleName::AppDev], true],
]);

it('asks for the same PCOV placement from every step on a Node with both application roles', function (): void {
    $node = php_package_node(RoleName::AppDev);
    $node->roles()->create(['role' => RoleName::AppProd]);
    $node->load('roles');
    $transport = new AppDevFakeSshExecutor;
    $manager = new RemotePhpPackageManager;

    $manager->installForAppDev($node, collect(['8.5']), php_package_app_dev_ssh($transport));
    $manager->installForInstance($node, collect(['8.5']), php_package_app_dev_ssh($transport), RoleName::AppProd);
    $manager->installForAppProd($node, collect(['8.5']), php_package_app_prod_ssh($transport));
    $manager->installPackagesOnlyForAppProd($node, collect(['8.5']), php_package_app_prod_ssh($transport));

    $installers = array_values(array_filter($transport->commands, static fn (RemoteCommand $command): bool => ($command->arguments[4] ?? null) === 'php.install-build'));
    expect($installers)->toHaveCount(4)
        ->and(array_map(static fn (RemoteCommand $command): bool => php_package_installer_input($command)['pcov'], $installers))
        ->toBe([true, true, true, true])
        ->and(array_map(static fn (RemoteCommand $command): bool => php_package_installer_input($command)['opentelemetry'], $installers))
        ->toBe([true, true, true, true]);
});

it('installs a dedicated master build without restarting any PHP-FPM service', function (): void {
    $transport = new AppDevFakeSshExecutor;

    new RemotePhpPackageManager()->installPackagesOnlyForAppProd(php_package_node(RoleName::AppProd), collect(['8.5']), php_package_app_prod_ssh($transport));

    expect($transport->commands)->toHaveCount(1)
        ->and(php_package_installer_input($transport->commands[0]))->toMatchArray(['fpm' => 'none', 'extras' => 'keep', 'pcov' => false, 'opentelemetry' => false]);
});

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

it('maps each installer refusal to the stable role-specific error and keeps the installer log', function (string $outcome, array $data, string $step, string $message): void {
    $transport = new AppDevFakeSshExecutor([
        new CommandResult(0, json_encode(['outcome' => $outcome, 'data' => $data], JSON_THROW_ON_ERROR)."\n", "{$outcome}: from the Node\n", 1, false),
    ]);

    expect(fn () => new RemotePhpPackageManager()->installForAppDev(php_package_node(RoleName::AppDev), collect(['8.5']), php_package_app_dev_ssh($transport)))
        ->toThrow(function (RuntimeConvergenceException $exception) use ($step, $message, $outcome): void {
            expect($exception->step)->toBe($step)
                ->and($exception->getMessage())->toContain($message)
                ->and($exception->result?->stderr)->toBe("{$outcome}: from the Node\n");
        });
    expect($transport->commands)->toHaveCount(1);
})->with([
    'another PHP source' => ['conflicting_source', ['sources' => ['/etc/apt/sources.list.d/ondrej.list']], 'php-package-source', 'ondrej.list'],
    'a missing file' => ['download_failed', ['package' => 'php8.5-cli', 'reason' => 'no source served the pinned file'], 'php-package-source', 'php8.5-cli'],
    'an unsupported system' => ['platform_unsupported', ['id' => 'ubuntu', 'codename' => 'noble'], 'php-package-source', 'ubuntu/noble'],
    'a rolled back build' => ['rolled_back', ['unit' => 'php8.5-fpm.service', 'reason' => 'the service did not start'], 'php-fpm-install', 'php8.5-fpm.service'],
    'other loaded extensions' => ['modules_mismatch', ['version' => '8.5', 'missing' => ['pcov'], 'extra' => []], 'php-fpm-install', 'Missing: [pcov]'],
]);

it('fails without a result as an installation failure', function (): void {
    $transport = new AppDevFakeSshExecutor([new CommandResult(0, "not json\n", 'Traceback', 1, false)]);

    expect(fn () => new RemotePhpPackageManager()->installForAppDev(php_package_node(RoleName::AppDev), collect(['8.5']), php_package_app_dev_ssh($transport)))
        ->toThrow(fn (RuntimeConvergenceException $exception) => expect($exception->errorCode)->toBe('app-dev.php_install_failed'));
});

it('logs a skipped download and dedicated masters that may still use the previous build or settings', function (): void {
    Log::spy();
    $transport = new AppDevFakeSshExecutor([
        new CommandResult(0, '{"outcome":"current","data":{"changed":[],"purged":[],"restarted":[],"deferred":[],"stale_masters":["orbit-shop-php8.5-fpm.service"]}}'."\n", '', 1, false),
    ]);

    new RemotePhpPackageManager()->installForAppDev(php_package_node(RoleName::AppDev), collect(['8.5']), php_package_app_dev_ssh($transport));

    expect($transport->commands)->toHaveCount(2);
    Log::shouldHaveReceived('info')->once()->with('PHP packages match the recipe; skipped the package download.', Mockery::any());
    Log::shouldHaveReceived('warning')->once()->with('Dedicated PHP-FPM masters were not restarted or reloaded; they may still use the previous PHP build or settings.', Mockery::on(static fn (array $context): bool => $context['masters'] === ['orbit-shop-php8.5-fpm.service']));
});

/** @return array<string, mixed> */
function php_package_installer_input(RemoteCommand $command): array
{
    [, $json] = explode("\0", $command->input ?? '', 2);

    return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
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
