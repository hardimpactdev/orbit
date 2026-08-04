<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Closures for ProcessDriver wake concurrency proofs.
 *
 * Defined outside Pest-generated test classes so Laravel SerializableClosure
 * can load them in fresh Artisan workers.
 */
final class RuntimeWakeProcessConcurrencyProbe
{
    /**
     * @return array{a: callable(): bool, b: callable(): bool}
     */
    public static function overlappingTasks(string $dir): array
    {
        return [
            'a' => static function () use ($dir): bool {
                $pid = getmypid();
                file_put_contents("{$dir}/a.pid", (string) $pid);
                file_put_contents(
                    "{$dir}/a.env",
                    getenv('LARAVEL_INVOKABLE_CLOSURE') === false ? 'missing' : 'present',
                );

                $deadline = microtime(true) + 5.0;

                while (microtime(true) < $deadline) {
                    if (is_file("{$dir}/b.pid")) {
                        file_put_contents("{$dir}/a.ok", '1');

                        return true;
                    }

                    usleep(5_000);
                }

                return false;
            },
            'b' => static function () use ($dir): bool {
                $pid = getmypid();
                file_put_contents("{$dir}/b.pid", (string) $pid);
                file_put_contents(
                    "{$dir}/b.env",
                    getenv('LARAVEL_INVOKABLE_CLOSURE') === false ? 'missing' : 'present',
                );

                $deadline = microtime(true) + 5.0;

                while (microtime(true) < $deadline) {
                    if (is_file("{$dir}/a.pid")) {
                        file_put_contents("{$dir}/b.ok", '1');

                        return true;
                    }

                    usleep(5_000);
                }

                return false;
            },
        ];
    }
}
