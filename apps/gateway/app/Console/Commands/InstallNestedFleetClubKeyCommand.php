<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Instances\NestedFleetClubKey;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\Tasks\SwampReaderEnvironment;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Throwable;

/**
 * Put the nested fleets' Swamp Club key into the shared Gateway env from stdin
 * (docs/reference/development-containers.md#the-club-key-of-the-nested-gateway). The value never appears in an argument or output.
 */
final class InstallNestedFleetClubKeyCommand extends Command
{
    private const int InputLimit = 4096;

    #[\Override]
    protected $signature = 'gateway:nested-fleet-club-key:install';

    #[\Override]
    protected $description = 'Install the nested fleets\' Swamp Club key from stdin with a private env backup.';

    public function handle(GatewayReleaseLock $lock, SwampReaderEnvironment $environment): int
    {
        try {
            $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;
            $stream ??= STDIN;
            if (stream_isatty($stream)) {
                throw new RuntimeException;
            }
            $value = stream_get_contents($stream, self::InputLimit + 1);
            // One token: printable ASCII without spaces or quotes, so the env line needs no quoting. One final newline is allowed.
            $value = is_string($value) ? (string) preg_replace('/\r?\n\z/', '', $value) : '';
            if (strlen($value) > self::InputLimit || preg_match('/\A[\x21\x23-\x26\x28-\x7e]{16,}\z/D', $value) !== 1) {
                throw new RuntimeException;
            }
            $backup = $lock->run(fn (): string => $environment->replace($environment->contents(), [NestedFleetClubKey::Setting => $value]));
            $this->line(json_encode(['setting' => NestedFleetClubKey::Setting, 'backup' => $backup], JSON_THROW_ON_ERROR));
            // Each run start reads the setting from the env file itself, so no configuration cache or worker reload is needed.
            $this->info('The next nested-fleet start reads the key from the env file and delivers it. No configuration reload is needed.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Nested-fleet Club key installation failed. Check stdin (one token), private env ownership and the release lock. No value was printed.');

            return self::FAILURE;
        }
    }
}
