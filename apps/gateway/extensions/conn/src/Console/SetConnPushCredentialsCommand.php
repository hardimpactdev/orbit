<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Orbit\Extensions\Conn\Actions\Push\SetConnPushCredentialsAction;
use Orbit\Extensions\Conn\Domain\Push\ConnPushCredentials;
use Symfony\Component\Console\Input\StreamableInputInterface;

/** Reads an Apple APNs .p8 private key from stdin into Orbit's encrypted secret storage. Never prints the key. */
final class SetConnPushCredentialsCommand extends Command
{
    /** @var string */
    #[\Override]
    protected $signature = 'conn:push-credentials:set
        {--key-id= : Apple APNs Key ID}
        {--team-id= : Apple Developer Team ID}
        {--json : Return JSON output}';

    /** @var string */
    #[\Override]
    protected $description = 'Store Apple APNs push credentials (.p8 private key read from stdin) in encrypted storage.';

    public function handle(SetConnPushCredentialsAction $action): int
    {
        $keyId = $this->option('key-id');
        $teamId = $this->option('team-id');

        if (! is_string($keyId) || trim($keyId) === '') {
            $this->error('--key-id is required.');

            return self::INVALID;
        }

        if (! is_string($teamId) || trim($teamId) === '') {
            $this->error('--team-id is required.');

            return self::INVALID;
        }

        $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;
        $stream ??= STDIN;
        if (stream_isatty($stream)) {
            $this->error('Provide the APNs .p8 private key on stdin.');

            return self::INVALID;
        }

        $pem = stream_get_contents($stream, ConnPushCredentials::MaxPrivateKeyBytes + 1);
        if ($pem === false || trim($pem) === '') {
            $this->error('APNs .p8 private key on stdin is empty.');

            return self::INVALID;
        }

        try {
            $status = $action->execute(trim($keyId), trim($teamId), trim($pem));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->line(json_encode($status->toArray(), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->line('Configured: yes');
        $this->line('Key ID: '.$status->keyId);
        $this->line('Team ID: '.$status->teamId);

        return self::SUCCESS;
    }
}
