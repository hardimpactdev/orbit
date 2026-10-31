<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Processes\ProtectedInput;
use Illuminate\Support\Facades\Cache;
use SensitiveParameter;
use Throwable;

/** The Gateway owns the value; Swamp receives its execution copy through native stdin. */
final readonly class SwampVaultExecutionCopy
{
    public function __construct(private ProcessRunner $processes) {}

    /** @return array{vault:string,key:string} */
    public function synchronize(string $vault, string $key, #[SensitiveParameter] string $value): array
    {
        $binary = config('swamp.binary', '/usr/local/bin/swamp');
        $server = config('swamp.vault_server', 'wss://swamp.orbit');
        $ca = config('swamp.ca_path');
        $token = config('swamp.run_token');
        if (preg_match('/\A[a-z][a-z0-9_-]{0,127}\z/D', $vault) !== 1
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,255}\z/D', $key) !== 1
            || ! is_string($binary) || ! str_starts_with($binary, '/')
            || ! is_string($server) || parse_url($server, PHP_URL_SCHEME) !== 'wss'
            || ! is_string(parse_url($server, PHP_URL_HOST)) || parse_url($server, PHP_URL_USER) !== null
            || parse_url($server, PHP_URL_PASS) !== null || parse_url($server, PHP_URL_QUERY) !== null
            || parse_url($server, PHP_URL_FRAGMENT) !== null
            || ! is_string($ca) || $ca === '' || ! is_string($token) || $token === '') {
            throw $this->unavailable();
        }
        $reference = ['vault' => $vault, 'key' => $key];
        $stamp = 'swamp:execution-copy:'.hash('sha256', $server."\0".$vault."\0".$key);
        $digest = hash('sha256', $value);
        if (Cache::get($stamp) === $digest) {
            return $reference;
        }
        $input = ProtectedInput::fromString($value);
        try {
            $result = $this->processes->run(new ProcessInvocation(
                arguments: [$binary, 'vault', 'put', $vault, $key, '--server', $server, '--ca-cert', $ca, '--force', '--json'],
                timeout: 30,
                protectedInput: $input,
                maxOutputBytes: 4096,
                environment: ['SWAMP_SERVER_TOKEN' => $token, 'SWAMP_NO_TELEMETRY' => '1', 'SWAMP_NO_UPDATE_CHECK' => '1'],
            ));
            if (! $result->succeeded()) {
                throw $this->unavailable();
            }
            Cache::forever($stamp, $digest);
        } catch (Throwable) {
            throw $this->unavailable();
        } finally {
            $input->close();
        }

        return $reference;
    }

    private function unavailable(): ResourceOperationException
    {
        return new ResourceOperationException('swamp.vault_sync_unavailable', 'The Swamp execution copy could not be stored.', 503);
    }
}
