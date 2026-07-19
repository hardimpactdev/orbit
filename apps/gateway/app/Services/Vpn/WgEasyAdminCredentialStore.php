<?php

declare(strict_types=1);

namespace App\Services\Vpn;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use SensitiveParameter;

final class WgEasyAdminCredentialStore
{
    private const string ENVIRONMENT_KEY = 'WG_EASY_PASSWORD';

    public function ensurePassword(): string
    {
        $password = $this->readPassword();

        if ($password === null) {
            $password = Str::random(32);
            $this->writePassword($password);
        }

        config()->set('services.wg_easy.password', $password);

        return $password;
    }

    private function readPassword(): ?string
    {
        $path = app()->environmentFilePath();

        if (! File::exists($path)) {
            return null;
        }

        $contents = File::get($path);

        $matches = [];

        if (preg_match('/^'.self::ENVIRONMENT_KEY.'=(.*)$/m', $contents, $matches) !== 1) {
            return null;
        }

        $value = trim($matches[1]);

        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
            return stripcslashes(substr(string: $value, offset: 1, length: -1));
        }

        if (str_starts_with($value, "'") && str_ends_with($value, "'")) {
            return str_replace(
                search: "\\'",
                replace: "'",
                subject: substr(string: $value, offset: 1, length: -1),
            );
        }

        return $value;
    }

    private function writePassword(#[SensitiveParameter] string $password): void
    {
        $path = app()->environmentFilePath();
        $contents = File::exists($path) ? File::get($path) : '';
        $line = self::ENVIRONMENT_KEY."={$password}";

        $contents = preg_match('/^'.self::ENVIRONMENT_KEY.'=/m', $contents) === 1
            ? (string) preg_replace('/^'.self::ENVIRONMENT_KEY.'=.*$/m', $line, $contents)
            : rtrim($contents)."\n{$line}\n";

        File::put($path, $contents);
        $_ENV[self::ENVIRONMENT_KEY] = $password;
        putenv(self::ENVIRONMENT_KEY."={$password}");
    }
}
