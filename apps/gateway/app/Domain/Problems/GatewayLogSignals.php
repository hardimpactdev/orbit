<?php

declare(strict_types=1);

namespace App\Domain\Problems;

/**
 * Reads the Gateway's own Laravel log from a cursor and keeps only safe exception signals. Messages, arguments and
 * local paths never leave this class.
 */
final readonly class GatewayLogSignals
{
    private const string Header = '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] [^.\[\]\s]+\.(DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY): /m';

    private const int Budget = 1048576;

    private const int Cap = 8388608;

    private const array ClientStatuses = [
        'BadRequestHttpException' => 400, 'UnauthorizedHttpException' => 401, 'AccessDeniedHttpException' => 403,
        'NotFoundHttpException' => 404, 'MethodNotAllowedHttpException' => 405, 'NotAcceptableHttpException' => 406,
        'ConflictHttpException' => 409, 'GoneHttpException' => 410, 'LengthRequiredHttpException' => 411,
        'PreconditionFailedHttpException' => 412, 'PreconditionRequiredHttpException' => 428,
        'UnsupportedMediaTypeHttpException' => 415, 'UnprocessableEntityHttpException' => 422,
        'LockedHttpException' => 423, 'TooManyRequestsHttpException' => 429, 'ServiceUnavailableHttpException' => 503,
    ];

    /**
     * The first pass starts at the current end. Rotation finishes the old inode; truncation resumes at the shorter end.
     *
     * @param  array{path: string, inode: int, offset: int}|null  $cursor
     * @return array{cursor: array{path: string, inode: int, offset: int}|null, signals: list<array{exception_class: string, frame: string, request_id: string|null, recorded_at: string}>}
     */
    public function collect(string $directory, string $base, ?array $cursor): array
    {
        clearstatcache();
        $files = [];
        foreach (is_dir($directory) ? scandir($directory) ?: [] : [] as $name) {
            $path = $directory.'/'.$name;
            $stat = preg_match('/\Alaravel.*\.log\z/', $name) === 1 && is_file($path) ? stat($path) : false;
            if ($stat !== false) {
                $files[] = ['path' => $path, 'inode' => $stat['ino'], 'size' => $stat['size'], 'mtime' => $stat['mtime']];
            }
        }
        $current = array_find($files, static fn (array $file): bool => basename($file['path']) === 'laravel.log');
        $daily = array_values(array_filter($files, static fn (array $file): bool => str_starts_with(basename($file['path']), 'laravel-')));
        usort($daily, static fn (array $a, array $b): int => [$a['mtime'], $a['path']] <=> [$b['mtime'], $b['path']]);
        $current ??= $daily === [] ? null : array_last($daily);
        $at = static fn (array $file, ?int $offset = null): array => ['path' => $file['path'], 'inode' => $file['inode'], 'offset' => $offset ?? $file['size']];

        if ($cursor === null) {
            return ['cursor' => $current === null ? null : $at($current), 'signals' => []];
        }
        $known = array_find($files, static fn (array $file): bool => $file['path'] === $cursor['path'] && $file['inode'] === $cursor['inode'])
            ?? array_find($files, static fn (array $file): bool => $file['inode'] === $cursor['inode']);
        $reading = $known ?? $current;
        if ($reading === null) {
            return ['cursor' => $cursor, 'signals' => []];
        }
        $start = $known !== null ? min($cursor['offset'], $reading['size']) : 0;
        $data = (string) file_get_contents($reading['path'], false, null, $start, self::Budget);
        $size = filesize($reading['path']) ?: 0;
        $starts = $this->starts($data);
        if ($start + strlen($data) < $size && count($starts) < 2) {
            $data .= (string) file_get_contents($reading['path'], false, null, $start + strlen($data), max(0, self::Cap - strlen($data)));
            $starts = $this->starts($data);
        }
        $eof = $start + strlen($data) >= $size;
        $consumed = match (true) {
            $eof, strlen($data) === self::Cap && count($starts) < 2 => strlen($data),
            count($starts) > 1 => array_last($starts),
            default => 0,
        };
        $text = substr($data, 0, $consumed);
        $starts = $this->starts($text);
        $signals = [];
        foreach ($starts as $index => $offset) {
            $end = $starts[$index + 1] ?? strlen($text);
            $signal = $this->signal(substr($text, $offset, $end - $offset), [rtrim($base, '/'), rtrim(realpath($base) ?: $base, '/')]);
            if ($signal !== null) {
                $signals[] = $signal;
            }
        }
        $next = $start + $consumed >= $size && $current !== null && $current['path'] !== $reading['path']
            ? $at($current, 0)
            : $at($reading, $start + $consumed);

        return ['cursor' => $next, 'signals' => $signals];
    }

    /** @return list<int> */
    private function starts(string $text): array
    {
        preg_match_all(self::Header, $text, $matches, PREG_OFFSET_CAPTURE);

        return array_column($matches[0], 1);
    }

    /**
     * @param  list<string>  $roots
     * @return array{exception_class: string, frame: string, request_id: string|null, recorded_at: string}|null
     */
    private function signal(string $record, array $roots): ?array
    {
        if (preg_match(self::Header, $record, $header) !== 1 || ! in_array($header[2], ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'], true)
            || preg_match('/\[object\] \(([A-Za-z_\\\\][A-Za-z0-9_\\\\]{0,511})\(code:\s*(-?\d{1,6})\)/', $record, $exception) !== 1) {
            return null;
        }
        $name = str_replace('\\\\', '\\', $exception[1]);
        $base = substr((string) strrchr('\\'.$name, '\\'), 1);
        $code = (int) $exception[2];
        $status = $base === 'HttpException' && $code >= 100 && $code <= 599 ? $code : (self::ClientStatuses[$base] ?? null);
        if ($base === 'ValidationException' || ($status !== null && $status < 500)) {
            return null;
        }
        $frame = null;
        preg_match_all('/^#\d+ (.{1,2048}?)\(\d+\): (.{1,2048})$/m', $record, $entries, PREG_SET_ORDER);
        foreach ($entries as $entry) {
            $path = str_replace('\\', '/', $entry[1]);
            $root = array_find($roots, static fn (string $root): bool => str_starts_with($path, $root.'/app/'));
            if ($root === null) {
                continue;
            }
            $relative = substr($path, strlen($root) + 1);
            if (preg_match('#\Aapp/[A-Za-z0-9_./-]+\z#', $relative) !== 1 || in_array('..', explode('/', $relative), true)) {
                continue;
            }
            $call = (string) preg_replace('/\([^)]*\)$/', '', rtrim(str_replace('\\\\', '\\', $entry[2])));
            $call = (string) preg_replace('/:\d+(?=})/', '', $call);
            if (preg_match('/\A[A-Za-z0-9_\\\\:>{}().-]{1,1024}\z/', $call) === 1) {
                $frame = $relative.':'.$call;
                break;
            }
        }
        if ($frame === null) {
            return null;
        }

        return [
            'exception_class' => $name,
            'frame' => $frame,
            'request_id' => preg_match('/"request_id"\s*:\s*"([A-Za-z0-9_.:-]{1,128})"/', $record, $request) === 1 ? $request[1] : null,
            'recorded_at' => $header[1],
        ];
    }
}
