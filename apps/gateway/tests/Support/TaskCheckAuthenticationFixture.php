<?php

declare(strict_types=1);

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\LocalShellSshExecutor;

function check_runner_authentication(string $ending, int $exitCode, string $directory): void
{
    Http::preventStrayRequests();
    GitHubTestSupport::storeApp();
    Http::fake([
        'https://api.github.com/repos/acme/shop/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_setup_sentinel'], 201),
    ]);
    $checkout = check_runner_checkout('true', realComposer: true, directory: $directory);
    $instance = check_runner_instance($checkout);
    File::ensureDirectoryExists($checkout.'/ignored');
    $transport = new class(check_runner_shell(new LocalShellSshExecutor, $directory)) implements SshExecutor
    {
        public array $commands = [];

        public function __construct(private readonly SshExecutor $local) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->commands[] = $command;

            return $this->local->execute($connection, $command);
        }
    };
    $runner = check_runner($transport);
    file_put_contents($checkout.'/AuthProbe.php', <<<'PHP_PROBE'
        <?php
        class AuthProbe {
            public static function check(\Composer\Script\Event $event): void {
                $token = $event->getIO()->getAuthentication('github.com')['username'];
                if (getenv('COMPOSER_AUTH') !== false || str_contains(file_get_contents('/proc/'.getmypid().'/environ'), $token)
                    || str_contains(file_get_contents('/proc/'.posix_getppid().'/environ'), $token)) {
                    throw new \RuntimeException('Composer inherited the secret in its environment.');
                }
                // The redactor retains the longest credential minus one byte. The marker becomes
                // visible only after all seven prefix bytes have entered that buffer.
                $auth = json_encode(['github-oauth' => ['github.com' => $token], 'bearer' => ['codeload.github.com' => $token]], JSON_THROW_ON_ERROR);
                $keep = max(strlen($auth), strlen(base64_encode($token.':x-oauth-basic')), strlen($token)) - 1;
                $marker = 'auth-read-'.bin2hex(random_bytes(16));
                echo $marker.str_repeat('.', $keep - 7).substr($token, 0, 7);
                flush();
                $deadline = microtime(true) + 3;
                do {
                    $log = file_get_contents(__DIR__.'/.git/orbit/check.log');
                    if (str_contains($log, $marker)) {
                        break;
                    }
                    usleep(1000);
                } while (microtime(true) < $deadline);
                if (! str_contains($log, $marker)) {
                    throw new \RuntimeException('The check did not consume the first authentication chunk.');
                }
                echo substr($token, 7)."\n";
                echo base64_encode($token.':x-oauth-basic')."\n";
                passthru('php -r \'exit(getenv("COMPOSER_AUTH") === false ? 0 : 1);\'', $exit);
                if ($exit !== 0) { throw new \RuntimeException('A script child inherited authentication.'); }
            }
        }
        PHP_PROBE);
    file_put_contents($checkout.'/composer.json', json_encode([
        'repositories' => ['packagist.org' => false],
        'autoload' => ['classmap' => ['AuthProbe.php']],
        'scripts' => [
            'post-autoload-dump' => ['AuthProbe::check', '@composer run-script nested-probe --no-interaction'],
            'nested-probe' => '@php -r "echo \'nested-composer-ok\'.PHP_EOL;"',
        ],
    ], JSON_THROW_ON_ERROR));
    $setup = <<<'BASH'
        python3 - <<'PY'
        import os
        assert 'COMPOSER_AUTH' not in os.environ
        assert 'ORBIT_SETUP_COMPOSER_AUTH' not in os.environ
        assert b'ORBIT_SETUP_COMPOSER_AUTH=' not in open('/proc/%s/environ' % os.getppid(), 'rb').read()
        assert os.environ['COMPOSER_CACHE_DIR'] == os.path.expanduser('~/.cache/composer')
        assert os.environ['ORBIT_SEED_PATH'] == ''
        assert os.environ['ORBIT_SEED_COMMIT'] == ''
        PY
        unset COMPOSER_BINARY
        composer install --no-interaction --no-plugins --no-progress
        BASH;

    $process = $runner->start($instance, 'test -z "${COMPOSER_AUTH:-}" && test -z "${ORBIT_SETUP_COMPOSER_AUTH:-}" && echo checked', [
        ['name' => 'install-apps-cli', 'command' => $setup."\n".$ending, 'timeout_seconds' => $exitCode === 124 ? 5 : 10],
    ]);
    try {
        ['result' => $result, 'output' => $output, 'gitDirectory' => $git] = check_runner_local_result($checkout, $process);
    } catch (Throwable $failure) {
        $runner->cancel($instance, $process);
        throw $failure;
    }

    expect($result['exit_code'])->toBe($exitCode);
    if ($exitCode === 0) {
        expect($output)->toContain('checked');
    }
    expect($output)->toContain('[REDACTED]')->not->toContain('ghs_setup_sentinel', base64_encode('ghs_setup_sentinel:x-oauth-basic'));
    expect($output)->toContain('nested-composer-ok');
    expect(file_get_contents($git.'/orbit/check.log'))->not->toContain('ghs_setup_sentinel');
    expect(file_get_contents($git.'/orbit/setup.json'))->not->toContain('ghs_setup_sentinel');
    foreach ($transport->commands as $command) {
        expect(implode(' ', $command->arguments).($command->input ?? ''))->not->toContain('ghs_setup_sentinel');
    }
    expect($transport->commands[0]->protectedInput)->not->toBeNull();
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
        && $request->data() === ['repositories' => ['shop'], 'permissions' => ['metadata' => 'read']]);
}
