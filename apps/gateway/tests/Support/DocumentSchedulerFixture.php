<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/** Real schedule:work and schedule:run processes, sharing immutable bootstrap artifacts and a clock/ack socket. */
final class DocumentSchedulerFixture
{
    private static string $bootstrap;

    public readonly Process $process;

    /** @var resource|null */
    private $server = null;

    /** @var resource|null */
    private $control = null;

    public function __construct(string $home, string $command, ?string $staleToken)
    {
        $bootstrap = self::$bootstrap;
        $socket = sys_get_temp_dir().'/scheduler-'.bin2hex(random_bytes(8)).'.sock';
        if ($command === 'schedule:work') {
            $this->server = stream_socket_server('unix://'.$socket);
            if ($this->server === false) {
                throw new RuntimeException('Could not listen for scheduler acknowledgements.');
            }
        }
        $this->process = new Process([PHP_BINARY, base_path('tests/Fixtures/ProjectDocuments/document_scheduler.php'), $command, '--no-interaction'], base_path(), [
            'ORBIT_HOME' => $home, 'ORBIT_DOCUMENT_CLEANUP_RUNTIME' => $home.'/runtime', 'APP_ENV' => 'testing',
            'DB_DATABASE' => $home.'/database.sqlite', 'DB_URL' => '', 'APP_KEY' => config()->string('app.key'),
            'ORBIT_DOCUMENT_SCHEDULER_SESSION' => $staleToken ?? false,
            'ORBIT_TEST_SCHEDULER_SOCKET' => $command === 'schedule:work' ? $socket : false,
            'APP_CONFIG_CACHE' => $bootstrap.'/bootstrap/config.php', 'APP_ROUTES_CACHE' => $bootstrap.'/bootstrap/routes.php',
            'APP_SERVICES_CACHE' => $bootstrap.'/bootstrap/services.php', 'APP_PACKAGES_CACHE' => $bootstrap.'/bootstrap/packages.php',
            'APP_EVENTS_CACHE' => $bootstrap.'/bootstrap/events.php', 'LARAVEL_STORAGE_PATH' => $home.'/storage',
            'PHP_INI_SCAN_DIR' => self::iniDirectory(),
        ], timeout: 5);
    }

    public function start(): void
    {
        $this->process->start();
        $this->control = $this->accept();
        expect(trim($this->line($this->control)))->toBe('scheduler-ready');
    }

    /** @return array<string, mixed> */
    public function tick(string $clock): array
    {
        deletion_scheduler_clock($clock);
        fwrite($this->control, $clock."\n");
        $ack = $this->accept();
        try {
            $tick = json_decode($this->line($ack), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            fclose($ack);
        }
        expect($tick['clock'])->toBe($clock);
        expect($tick['exit_code'])->toBe(0);

        return $tick;
    }

    /** @return resource */
    private function accept()
    {
        $connection = @stream_socket_accept($this->server, 5);
        if ($connection === false) {
            throw new RuntimeException('Missing scheduler acknowledgement: '.$this->process->getOutput().$this->process->getErrorOutput());
        }
        stream_set_timeout($connection, 5);

        return $connection;
    }

    /** @param resource $connection */
    private function line($connection): string
    {
        $line = fgets($connection);
        if ($line === false) {
            throw new RuntimeException('Incomplete scheduler acknowledgement: '.$this->process->getOutput().$this->process->getErrorOutput());
        }

        return $line;
    }

    public static function prepareBootstrap(): void
    {
        $home = self::$bootstrap = TestTemporaryDirectory::create(sys_get_temp_dir());
        mkdir($home.'/bootstrap', 0o700);
        mkdir($home.'/php', 0o700);
        mkdir($home.'/bytecode', 0o700);
        file_put_contents($home.'/scheduler-clock', '2030-01-01T00:00:01Z');
        // Each real PHP child reuses compiled, immutable framework/bootstrap sources instead of parsing them again.
        file_put_contents($home.'/php/scheduler.ini', "opcache.enable_cli=1\nopcache.file_cache_only=1\nopcache.file_update_protection=0\nopcache.file_cache=$home/bytecode\n");
        $base = dirname(__DIR__, 2);
        new Process([PHP_BINARY, $base.'/tests/Fixtures/ProjectDocuments/document_scheduler.php', 'fixture:bootstrap'], $base, [
            'ORBIT_HOME' => $home, 'APP_ENV' => 'testing', 'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '', 'CACHE_STORE' => 'array',
            'APP_CONFIG_CACHE' => $home.'/bootstrap/config.php', 'APP_ROUTES_CACHE' => $home.'/bootstrap/routes.php',
            'APP_SERVICES_CACHE' => $home.'/bootstrap/services.php', 'APP_PACKAGES_CACHE' => $home.'/bootstrap/packages.php',
            'APP_EVENTS_CACHE' => $home.'/bootstrap/events.php', 'LARAVEL_STORAGE_PATH' => $home.'/storage',
            'PHP_INI_SCAN_DIR' => self::iniDirectory(),
        ])->mustRun();
        foreach (glob($home.'/bootstrap/*.php') as $artifact) {
            chmod($artifact, 0o400);
        }
    }

    private static function iniDirectory(): string
    {
        return (getenv('PHP_INI_SCAN_DIR') ?: PHP_CONFIG_FILE_SCAN_DIR).PATH_SEPARATOR.self::$bootstrap.'/php';
    }

    public function __destruct()
    {
        $this->process->stop(0);
        if (is_resource($this->control)) {
            fclose($this->control);
        }
        if (is_resource($this->server)) {
            fclose($this->server);
        }
    }
}
