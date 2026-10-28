<?php

declare(strict_types=1);

namespace App\Commands\Vault;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Vault\SetupVaultRequest;

final class SetupVaultCommand extends VaultCommand
{
    #[\Override]
    protected $signature = 'vault:setup
        {--url= : Vaultwarden origin, for example https://vault.orbit}
        {--client-id= : The client_id of the Gateway account personal API key}
        {--client-secret-file= : File that contains only the API key client_secret}
        {--master-password-file= : File that contains only the account master password}
        {--organization= : Organization name or ID}
        {--collection= : Collection name or ID in that organization}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Store the Vaultwarden account, organization, and collection.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        $connector = $this->gatewayConnector($repository, $factory);

        if ($connector === null) {
            return self::FAILURE;
        }

        $url = $this->stringOption('url');

        if ($url === null || ! self::isHttpsOrigin($url)) {
            return $this->renderGatewayFailure(
                'vault.url_invalid',
                'Vaultwarden origin must be an HTTPS URL, for example https://vault.orbit.',
                details: ['field' => 'url'],
            );
        }

        $clientId = $this->requiredOption('client-id', 'Supply --client-id.');
        $organization = $clientId === null ? null : $this->requiredOption('organization', 'Supply --organization.');
        $collection = $organization === null ? null : $this->requiredOption('collection', 'Supply --collection.');

        if ($clientId === null || $organization === null || $collection === null) {
            return self::FAILURE;
        }

        $clientSecret = $this->secretFromFile('client-secret-file', 'client secret');
        $masterPassword = $clientSecret === null ? null : $this->secretFromFile('master-password-file', 'master password');

        if ($clientSecret === null || $masterPassword === null) {
            return self::FAILURE;
        }

        return $this->integrationOperation($connector, $repository, new SetupVaultRequest($url, $clientId, $clientSecret, $masterPassword, $organization, $collection, is_string($this->option('idempotency-key')) ? $this->option('idempotency-key') : null), 'Set up vault');
    }

    private function requiredOption(string $option, string $message): ?string
    {
        $value = $this->stringOption($option);

        if ($value === null || trim($value) === '') {
            $this->renderGatewayFailure('input.invalid', $message, details: ['field' => $option]);

            return null;
        }

        return trim($value);
    }

    private function secretFromFile(string $option, string $label): ?string
    {
        $path = $this->stringOption($option);

        if ($path === null) {
            $this->renderGatewayFailure(
                'vault.secret_required',
                "Supply --{$option}. The CLI never accepts the {$label} on the command line.",
                details: ['field' => $option],
            );

            return null;
        }

        if (! is_file($path) || ! is_readable($path)) {
            $this->renderGatewayFailure(
                'vault.secret_invalid',
                "The {$label} file is unreadable.",
                details: ['field' => $option],
            );

            return null;
        }

        $secret = rtrim((string) file_get_contents($path), "\r\n");

        if (trim($secret) === '') {
            $this->renderGatewayFailure(
                'vault.secret_invalid',
                "The {$label} file is empty.",
                details: ['field' => $option],
            );

            return null;
        }

        return $secret;
    }

    private static function isHttpsOrigin(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') !== ''
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && in_array($parts['path'] ?? '', ['', '/'], true);
    }
}
