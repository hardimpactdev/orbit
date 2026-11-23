<?php

declare(strict_types=1);

namespace App\Commands\Conn;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use App\Support\GatedExtensionCommand;
use Orbit\Sdk\Requests\Conn\SetConnPushCredentialsRequest;
use Orbit\Sdk\Responses\Conn\ConnPushCredentialsResponse;
use Symfony\Component\Console\Input\StreamableInputInterface;

final class SetConnPushCredentialsCommand extends GatewayCommand implements GatedExtensionCommand
{
    private const int MaxPrivateKeyBytes = 16384;

    #[\Override]
    protected $signature = 'conn:push-credentials:set
        {--key-id= : Apple APNs Key ID}
        {--team-id= : Apple Developer Team ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Store Apple APNs push credentials (.p8 private key read from stdin) on the Gateway';

    public function extensionSlug(): string
    {
        return 'conn';
    }

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        $keyId = $this->validIdentifier($this->option('key-id'), 'key_id', 'conn.push_key_id_invalid');
        if ($keyId === null) {
            return self::FAILURE;
        }

        $teamId = $this->validIdentifier($this->option('team-id'), 'team_id', 'conn.push_team_id_invalid');
        if ($teamId === null) {
            return self::FAILURE;
        }

        $pem = $this->readPrivateKeyFromStdin();
        if ($pem === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $factory);
        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new SetConnPushCredentialsRequest($keyId, $teamId, $pem),
            ConnPushCredentialsResponse::class,
            ['Store Conn push credentials', 'Storing APNs credentials', 'Stored APNs credentials'],
        );

        if (! $response instanceof ConnPushCredentialsResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail('Conn APNs push credentials are stored.', [
            'Configured' => $response->configured ? 'yes' : 'no',
            'Key ID' => $response->keyId ?? 'none',
            'Team ID' => $response->teamId ?? 'none',
            'Request ID' => $response->requestId,
        ]));

        return self::SUCCESS;
    }

    private function validIdentifier(mixed $value, string $field, string $errorCode): ?string
    {
        $trimmed = is_string($value) ? trim($value) : '';

        if (preg_match('/\A[A-Za-z0-9]{1,32}\z/D', $trimmed) !== 1) {
            $this->renderGatewayFailure(
                $errorCode,
                "Provide --{$field} as 1 to 32 alphanumeric characters.",
                details: ['field' => $field],
            );

            return null;
        }

        return $trimmed;
    }

    private function readPrivateKeyFromStdin(): ?string
    {
        $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;
        $stream ??= STDIN;

        if (stream_isatty($stream)) {
            $this->renderGatewayFailure(
                'conn.push_private_key_invalid',
                'Pipe the APNs .p8 private key on stdin.',
                details: ['field' => 'private_key'],
            );

            return null;
        }

        $raw = stream_get_contents($stream, self::MaxPrivateKeyBytes + 1);
        $pem = is_string($raw) ? trim($raw) : '';

        if ($pem === '' || strlen($pem) > self::MaxPrivateKeyBytes) {
            $this->renderGatewayFailure(
                'conn.push_private_key_invalid',
                'The APNs .p8 private key on stdin must be non-empty and within 16 KiB.',
                details: ['field' => 'private_key'],
            );

            return null;
        }

        return $pem;
    }
}
