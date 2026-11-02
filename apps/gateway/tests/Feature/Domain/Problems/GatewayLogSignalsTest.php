<?php

declare(strict_types=1);

use App\Domain\Problems\GatewayLogSignals;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

function gateway_log_record(string $class = 'RuntimeException', string $path = '/gateway/app/Jobs/Probe.php', string $call = 'App\\\\Jobs\\\\Probe->handle()', string $message = 'secret-message-sentinel'): string
{
    return "[2026-10-31 10:20:30] testing.ERROR: {$message} {\"request_id\":\"request-123\",\"exception\":\"[object] ({$class}(code: 0): {$message} at {$path}:1)\n[stacktrace]\n#0 /vendor/framework.php(5): ignore()\n#1 {$path}(15): {$call}\n\"}\n";
}

/** @return array{cursor: array{path: string, inode: int, offset: int}|null, signals: list<array<string, string|null>>} */
function gateway_log_collect(string $directory, ?array $cursor): array
{
    return app(GatewayLogSignals::class)->collect($directory, '/gateway', $cursor);
}

beforeEach(function (): void {
    $this->logDirectory = sys_get_temp_dir().'/gateway-log-signals-'.bin2hex(random_bytes(6));
    mkdir($this->logDirectory);
});

afterEach(function (): void {
    File::deleteDirectory($this->logDirectory);
});

it('starts at the current end, then returns only safe signals of new records', function (): void {
    $path = $this->logDirectory.'/laravel.log';
    file_put_contents($path, gateway_log_record());
    $first = gateway_log_collect($this->logDirectory, null);
    expect($first['signals'])->toBe([])->and($first['cursor']['offset'])->toBe(filesize($path));

    file_put_contents($path, gateway_log_record(), FILE_APPEND);
    $next = gateway_log_collect($this->logDirectory, $first['cursor']);

    expect($next['signals'])->toBe([[
        'exception_class' => 'RuntimeException',
        'frame' => 'app/Jobs/Probe.php:App\\Jobs\\Probe->handle',
        'request_id' => 'request-123',
        'recorded_at' => '2026-10-31 10:20:30',
    ]])->and(json_encode($next))->not->toContain('secret-message-sentinel');
});

it('finishes a rotated inode, then reads the new file from zero', function (): void {
    $path = $this->logDirectory.'/laravel.log';
    file_put_contents($path, '');
    $initial = gateway_log_collect($this->logDirectory, null);
    file_put_contents($path, gateway_log_record(), FILE_APPEND);
    rename($path, $this->logDirectory.'/laravel-rotated.log');
    file_put_contents($path, gateway_log_record('LogicException'));

    $rotated = gateway_log_collect($this->logDirectory, $initial['cursor']);
    expect(array_column($rotated['signals'], 'exception_class'))->toBe(['RuntimeException'])
        ->and($rotated['cursor'])->toMatchArray(['path' => $path, 'offset' => 0]);

    expect(array_column(gateway_log_collect($this->logDirectory, $rotated['cursor'])['signals'], 'exception_class'))->toBe(['LogicException']);
});

it('resumes a truncated file at its new end and restarts a replaced inode', function (): void {
    $path = $this->logDirectory.'/laravel.log';
    file_put_contents($path, gateway_log_record());
    $initial = gateway_log_collect($this->logDirectory, null);
    file_put_contents($path, 'short');
    $truncated = gateway_log_collect($this->logDirectory, $initial['cursor']);
    expect($truncated['cursor']['offset'])->toBe(5)->and($truncated['signals'])->toBe([]);

    // Hold the old inode with a hard link, so the replacement cannot reuse it.
    link($path, $this->logDirectory.'/old-inode');
    unlink($path);
    file_put_contents($path, gateway_log_record('LogicException'));

    expect(array_column(gateway_log_collect($this->logDirectory, $truncated['cursor'])['signals'], 'exception_class'))->toBe(['LogicException']);
});

it('selects the newest daily file, and laravel.log before any daily file', function (): void {
    file_put_contents($older = $this->logDirectory.'/laravel-2026-10-30.log', gateway_log_record());
    file_put_contents($newer = $this->logDirectory.'/laravel-2026-10-31.log', gateway_log_record());
    touch($older, 100);
    touch($newer, 200);
    expect(gateway_log_collect($this->logDirectory, null)['cursor']['path'])->toBe($newer);

    file_put_contents($this->logDirectory.'/laravel.log', '');
    expect(gateway_log_collect($this->logDirectory, null)['cursor']['path'])->toBe($this->logDirectory.'/laravel.log');
});

it('ignores validation, sub-500 HTTP exceptions, vendor frames and warnings', function (): void {
    $path = $this->logDirectory.'/laravel.log';
    file_put_contents($path, '');
    $initial = gateway_log_collect($this->logDirectory, null);
    file_put_contents($path, implode('', [
        gateway_log_record('ValidationException'),
        gateway_log_record('Symfony\\\\Component\\\\HttpKernel\\\\Exception\\\\NotFoundHttpException'),
        gateway_log_record('RuntimeException', '/vendor/framework.php'),
        str_replace('.ERROR:', '.WARNING:', gateway_log_record()),
        gateway_log_record('Symfony\\\\Component\\\\HttpKernel\\\\Exception\\\\ServiceUnavailableHttpException'),
    ]));

    expect(array_column(gateway_log_collect($this->logDirectory, $initial['cursor'])['signals'], 'exception_class'))
        ->toBe([ServiceUnavailableHttpException::class]);
});

it('drops closure line numbers and runtime arguments from frames', function (): void {
    $path = $this->logDirectory.'/laravel.log';
    file_put_contents($path, '');
    $initial = gateway_log_collect($this->logDirectory, null);
    file_put_contents($path, gateway_log_record('RuntimeException', '/gateway/app/Http/Middleware/Access.php',
        'Illuminate\\\\Pipeline\\\\Pipeline->{closure:{closure:Illuminate\\\\Pipeline\\\\Pipeline::carry():194}:195}(secret-argument)'));
    $next = gateway_log_collect($this->logDirectory, $initial['cursor']);

    expect($next['signals'][0]['frame'])->toBe('app/Http/Middleware/Access.php:Illuminate\\Pipeline\\Pipeline->{closure:{closure:Illuminate\\Pipeline\\Pipeline::carry()}}')
        ->and(json_encode($next))->not->toContain('secret-argument');
});

it('reads a large record past the budget and advances past the hard cap', function (): void {
    $path = $this->logDirectory.'/laravel.log';
    file_put_contents($path, '');
    $initial = gateway_log_collect($this->logDirectory, null);
    file_put_contents($path, gateway_log_record().str_repeat('x', 1100000)."\n".gateway_log_record('LogicException'));
    $next = gateway_log_collect($this->logDirectory, $initial['cursor']);
    expect($next['signals'])->toHaveCount(2)->and($next['cursor']['offset'])->toBe(filesize($path));

    file_put_contents($path, str_repeat('x', 8388608 + 100));
    $capped = gateway_log_collect($this->logDirectory, [...$initial['cursor'], 'offset' => 0]);
    expect($capped['cursor']['offset'])->toBe(8388608)->and($capped['signals'])->toBe([]);
});

it('keeps the cursor when the files are gone and has none for an empty directory', function (): void {
    expect(gateway_log_collect($this->logDirectory, null))->toBe(['cursor' => null, 'signals' => []]);
    $cursor = ['path' => $this->logDirectory.'/laravel.log', 'inode' => 9999, 'offset' => 100];
    expect(gateway_log_collect($this->logDirectory, $cursor))->toBe(['cursor' => $cursor, 'signals' => []]);
});
