<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Instances\ComposerDependencyUpdateProgram;
use App\Infrastructure\Instances\DependencyUpdateSupervisorHost;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProtectedInput;
use Closure;
use Illuminate\Support\Str;
use stdClass;
use Symfony\Component\Process\Process;

final class ComposerUpdateFixture
{
    public static function directory(string $suffix = ''): string
    {
        $path = sys_get_temp_dir().'/orbit-composer-update-program-'.Str::uuid().$suffix;
        mkdir($path, 0o700);

        return $path;
    }

    public static function write(string $path, string $preUpdate): void
    {
        file_put_contents($path.'/composer.json', json_encode([
            'name' => 'orbit/composer-update-program-fixture',
            'require' => new stdClass,
            'repositories' => [['packagist.org' => false]],
            'scripts' => [
                'pre-update-cmd' => $preUpdate,
                'post-update-cmd' => 'printf done > done-marker',
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        (new Process([
            \dependency_update_supervisor_host() === DependencyUpdateSupervisorHost::Node
                ? '/usr/bin/composer'
                : TestToolchain::require('composer', 'brew install composer'),
            '--working-dir', $path, 'update', '--no-install', '--no-scripts', '--no-interaction', '--no-ansi', '--no-audit',
        ], env: ['COMPOSER_ALLOW_SUPERUSER' => '1'], timeout: 30))->mustRun();
    }

    public static function run(string $path, string $deadline, ?Closure $cancelled = null, float $timeout = 15.0): CommandResult
    {
        return new NativeProcessRunner()->run(new ProcessInvocation(
            arguments: [
                ...\dependency_update_supervisor_host()->launcher(),
                ComposerDependencyUpdateProgram::render(\dependency_update_supervisor_host()),
                'composer-update', $path, $deadline,
            ],
            timeout: $timeout,
            terminateGraceSeconds: ComposerDependencyUpdateProgram::TerminateGraceSeconds,
            environment: ['COMPOSER_ALLOW_SUPERUSER' => '1'],
            protectedInput: ProtectedInput::holdOpen(),
            cancelled: $cancelled,
        ));
    }
}
