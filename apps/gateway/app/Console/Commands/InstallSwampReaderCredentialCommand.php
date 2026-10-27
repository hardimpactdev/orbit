<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Tasks\InstallSwampReaderCredentialAction;
use App\Domain\Tasks\SwampReaderCredential;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Throwable;

final class InstallSwampReaderCredentialCommand extends Command
{
    #[\Override]
    protected $signature = 'gateway:swamp-reader:install {--expires-at= : UTC expiresAt from the Swamp mint result}';

    #[\Override]
    protected $description = 'Verify and install a Swamp reader reveal from stdin with a private env backup.';

    public function handle(InstallSwampReaderCredentialAction $action): int
    {
        try {
            $expiry = $this->option('expires-at');
            if (! is_string($expiry) || $expiry === '') {
                throw new RuntimeException;
            }
            $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;
            $stream ??= STDIN;
            if (stream_isatty($stream)) {
                throw new RuntimeException;
            }
            $json = stream_get_contents($stream, SwampReaderCredential::InputLimit + 1);
            if ($json === false) {
                throw new RuntimeException;
            }
            $result = $action->execute(SwampReaderCredential::fromReveal($json, $expiry));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
            $this->info('Apply Gateway configuration and verify a fresh serving read before revoking the old token.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Swamp reader installation failed. Check stdin, expiry, private env ownership, release lock, and Swamp access. No credential was printed.');

            return self::FAILURE;
        }
    }
}
