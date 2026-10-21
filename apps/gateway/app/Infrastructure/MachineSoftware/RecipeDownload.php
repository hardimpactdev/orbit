<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final readonly class RecipeDownload
{
    /** Download to a disposable file so release artifacts need not fit in PHP memory. Caller removes it. */
    public function file(string $url, bool $redirects = false, int $timeout = 600): string
    {
        $path = tempnam(sys_get_temp_dir(), 'orbit-recipe-');
        if ($path === false) {
            throw new RuntimeException('Cannot create a temporary recipe download file.');
        }
        try {
            $response = Http::connectTimeout(15)->timeout($timeout)->retry(
                [10000, 20000, 30000],
                when: fn (?Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()),
                throw: false,
            )->withOptions([
                'allow_redirects' => $redirects ? ['max' => 5, 'protocols' => ['https']] : false,
                'sink' => $path,
            ])->get($url);
            if ($response->status() !== 200) {
                throw new RuntimeException("HTTP {$response->status()}; redirects/fallback do not establish snapshot availability.");
            }
            // Laravel's HTTP fake returns a body without writing Guzzle's sink.
            if (filesize($path) === 0) {
                file_put_contents($path, $response->body());
            }

            return $path;
        } catch (Throwable $exception) {
            @unlink($path);
            throw new RuntimeException("Cannot verify [{$url}]: {$exception->getMessage()}", previous: $exception);
        }
    }
}
