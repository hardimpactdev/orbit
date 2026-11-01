<?php

declare(strict_types=1);

$directWriters = json_decode((string) env('ORBIT_SWAMP_DIRECT_WRITERS', '{}'), true);
if (is_array($directWriters)) {
    $directWriters = ['instance.deploy' => 'direct', ...$directWriters];
}

$token = static function (string $name): string {
    $path = env($name.'_FILE');
    if (! is_string($path) || $path === '') {
        return (string) env($name, '');
    }
    $stat = @lstat($path);
    if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 07777) !== 0600
        || $stat['uid'] !== posix_geteuid()) {
        throw new RuntimeException('Swamp token file must be an owned mode-0600 regular file.');
    }
    $value = @file_get_contents($path);
    if (! is_string($value) || trim($value) === '' || preg_match('/[\x00-\x20]/', trim($value)) === 1) {
        throw new RuntimeException('Swamp token file is invalid.');
    }

    return trim($value);
};

$port = env('ORBIT_SWAMP_PORT', 443);
if ((! is_int($port) && (! is_string($port) || preg_match('/\A[1-9][0-9]{0,4}\z/D', $port) !== 1))
    || (int) $port < 1 || (int) $port > 65535) {
    throw new RuntimeException('Swamp TLS port must be an integer from 1 to 65535.');
}

return [
    'address' => env('ORBIT_SWAMP_ADDRESS', 'swamp.orbit'),
    'port' => (int) $port,
    'ssh_public_key' => env('ORBIT_SWAMP_SSH_PUBLIC_KEY', 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND'),
    'read_token' => $token('ORBIT_SWAMP_READ_TOKEN'),
    'run_token' => $token('ORBIT_SWAMP_RUN_TOKEN'),
    'direct_writers' => $directWriters,
    'ca_path' => env('ORBIT_SWAMP_CA_PATH', rtrim((string) env('ORBIT_HOME', getenv('HOME').'/.orbit'), '/').'/ca/root.pem'),
];
