<?php

declare(strict_types=1);

namespace App\Commands\Analytics;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Laravel\Prompts\PasswordPrompt;
use Orbit\Sdk\Requests\Analytics\SetAnalyticsCredentialsRequest;
use Orbit\Sdk\Requests\Analytics\ShowAnalyticsCredentialsRequest;
use Orbit\Sdk\Requests\Analytics\UnsetAnalyticsCredentialsRequest;
use Orbit\Sdk\Responses\Analytics\AnalyticsCredentialsResponse;
use SensitiveParameter;
use Symfony\Component\Console\Input\StreamableInputInterface;

final class CredentialsAnalyticsCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'analytics:credentials
        {--set : Store a Plausible Stats API key}
        {--api-key= : The key to store; prompted when --set is used in a terminal}
        {--management-email= : Existing Plausible owner email; prompts for its password}
        {--management-password-stdin : Read one management password line from stdin}
        {--unset : Clear all stored Plausible credentials}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show Plausible credential status, store credentials, or clear them.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        $set = $this->option('set') === true;
        $unset = $this->option('unset') === true;
        $email = $this->option('management-email');
        $passwordStdin = $this->option('management-password-stdin') === true;

        if ($set && $unset) {
            return $this->renderGatewayFailure(
                'analytics.credentials_conflict',
                'Use --set or --unset, not both.',
            );
        }
        if ((! $set && ($email !== null || $passwordStdin)) || (is_string($email) && (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false))) {
            return $this->renderGatewayFailure('analytics.management_credentials_invalid', 'Use a valid management email with --set; management credentials are write-only.');
        }

        $connector = $this->gatewayConnector($repository, $factory);

        if ($connector === null) {
            return self::FAILURE;
        }

        if ($unset) {
            $response = $this->sendWithProgress(
                $connector,
                new UnsetAnalyticsCredentialsRequest,
                AnalyticsCredentialsResponse::class,
                ['Clear analytics credentials', 'Clearing Plausible credentials', 'Cleared Plausible credentials'],
            );

            return $this->render($response, 'Plausible credentials are no longer stored.');
        }

        if ($set) {
            $key = $this->apiKey();

            if ($key === null) {
                return self::FAILURE;
            }
            $password = null;
            if ($passwordStdin || is_string($email)) {
                $password = $this->managementPassword($passwordStdin);
                if ($password === null) {
                    return self::FAILURE;
                }
            }

            $response = $this->sendWithProgress(
                $connector,
                new SetAnalyticsCredentialsRequest($key, is_string($email) ? $email : null, $password),
                AnalyticsCredentialsResponse::class,
                ['Store analytics credentials', 'Storing the Stats API key', 'Stored the Stats API key'],
            );

            return $this->render($response, 'The Plausible Stats API key is stored.');
        }

        $response = $this->sendWithProgress(
            $connector,
            new ShowAnalyticsCredentialsRequest,
            AnalyticsCredentialsResponse::class,
            ['Show analytics credentials', 'Loading credential status', 'Loaded credential status'],
        );

        return $this->render(
            $response,
            $response instanceof AnalyticsCredentialsResponse && $response->configured
                ? 'A Plausible Stats API key is stored.'
                : 'No Plausible Stats API key is stored.',
        );
    }

    private function managementPassword(bool $stdin): ?string
    {
        $password = null;
        if ($stdin) {
            $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;
            $stream ??= defined('STDIN') ? STDIN : null;
            if (is_resource($stream) && ! stream_isatty($stream)) {
                $line = fgets($stream, 4099);
                $password = is_string($line) ? preg_replace('/\r?\n\z/', '', $line) : null;
            }
        } elseif ($this->consoleMode()->mayPrompt) {
            $answer = $this->commandPrompts()->run(fn (): PasswordPrompt => new PasswordPrompt(
                'Plausible management password', required: true, validate: $this->passwordError(...),
            ));
            $password = is_string($answer) ? $answer : null;
        }
        if ($password === null || $this->passwordError($password) !== null) {
            $this->renderGatewayFailure('analytics.management_password_invalid', 'Provide a nonempty management password through piped stdin with --management-password-stdin, or use the hidden prompt.');

            return null;
        }

        return $password;
    }

    private function passwordError(#[SensitiveParameter] string $password): ?string
    {
        return trim($password) === '' || strlen($password) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $password) === 1
            ? 'Use a nonempty password of at most 4096 bytes without control characters.' : null;
    }

    private function apiKey(): ?string
    {
        $key = $this->option('api-key');

        if (is_string($key) && $key !== '') {
            return $this->validKey($key);
        }

        if ($this->consoleMode()->mayPrompt) {
            $key = $this->commandPrompts()->run(fn (): PasswordPrompt => new PasswordPrompt(
                'Plausible Stats API key',
                required: true,
            ));

            return is_string($key) ? $this->validKey($key) : null;
        }

        $this->renderGatewayFailure(
            'analytics.api_key_invalid',
            'Provide --api-key, or run this command in a terminal.',
            details: ['field' => 'api_key'],
        );

        return null;
    }

    private function validKey(#[SensitiveParameter] string $key): ?string
    {
        if (strlen($key) < 8 || strlen($key) > 512 || preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
            $this->renderGatewayFailure(
                'analytics.api_key_invalid',
                'A Stats API key is 8 to 512 characters with no control characters.',
                details: ['field' => 'api_key'],
            );

            return null;
        }

        return $key;
    }

    private function render(?AnalyticsCredentialsResponse $response, string $title): int
    {
        if (! $response instanceof AnalyticsCredentialsResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail($title, [
            'Stored' => $response->configured ? 'yes' : 'no',
            'Management email stored' => $response->managementEmailConfigured ? 'yes' : 'no',
            'Management password stored' => $response->managementPasswordConfigured ? 'yes' : 'no',
            'Driver' => $response->driver,
            'Request ID' => $response->requestId,
        ]));

        return self::SUCCESS;
    }
}
