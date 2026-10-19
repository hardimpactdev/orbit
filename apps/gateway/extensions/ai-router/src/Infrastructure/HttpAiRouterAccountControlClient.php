<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Infrastructure;

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Certificates\OrbitCaCurlFactory;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Orbit\Extensions\AiRouter\Domain\AiRouterAccountControlClient;
use Orbit\Extensions\AiRouter\Domain\AiRouterHostname;
use SensitiveParameter;
use Throwable;

final readonly class HttpAiRouterAccountControlClient implements AiRouterAccountControlClient
{
    public function __construct(private LeafCertificateSigner $certificates) {}

    public function setDisabled(
        string $wireguardIp,
        int $port,
        #[SensitiveParameter] string $controlToken,
        string $account,
        bool $disabled,
    ): void {
        try {
            $this->certificates->rootCertificate();
            $caFile = rtrim(Config::string('orbit.home'), '/').'/ca/root.pem';

            Http::setHandler(new CurlHandler(['handle_factory' => new OrbitCaCurlFactory]))
                ->timeout(10)
                ->acceptJson()
                ->withToken($controlToken)
                ->withHeaders(['Host' => AiRouterHostname::Value])
                ->withOptions(['verify' => $caFile])
                ->patch("https://{$wireguardIp}:{$port}/v1/accounts/".rawurlencode($account), ['disabled' => $disabled])
                ->throw();
        } catch (Throwable $exception) {
            if ($exception instanceof ResourceOperationException) {
                throw $exception;
            }

            throw new ResourceOperationException(
                'ai_router.upstream_failed',
                'The ai-router collector refused the account update.',
                502,
                previous: $exception,
            );
        }
    }
}
