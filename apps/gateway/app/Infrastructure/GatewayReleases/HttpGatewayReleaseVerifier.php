<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseVerifier;
use App\Infrastructure\Certificates\OrbitCaCurlFactory;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * `/up` plus Gateway status, the same checks as `bin/deploy-verify`. The version matches only the
 * full commit or its 12-digit release id. A busy pool can queue `/up` behind long requests, so a
 * failed check is tried again with backoff before the release fails.
 */
final readonly class HttpGatewayReleaseVerifier implements GatewayReleaseVerifier
{
    /** @param list<int> $backoff Seconds to wait before each retry; about a minute in all by default. */
    public function __construct(
        #[Config('orbit.gateway_verify_origin')] private string $origin = 'https://gateway.orbit',
        private string $caFile = '/etc/caddy/orbit-cert-current/root-ca.pem',
        private array $backoff = [2, 4, 8, 16, 30],
    ) {}

    public function verify(string $sha): array
    {
        return $this->retrying($sha);
    }

    public function serving(): array
    {
        return $this->retrying(null);
    }

    /** @return array{status: string, version: string} */
    private function retrying(?string $sha): array
    {
        $result = null;

        try {
            $this->http()
                ->retry(array_map(static fn (int $seconds): int => $seconds * 1000, $this->backoff), throw: false)
                ->afterResponse(function (Response $up) use ($sha, &$result): Response {
                    $this->assertResponse($up, 'Gateway /up did not succeed.');
                    $result = $this->check($sha);

                    return $up;
                })
                ->get($this->origin.'/up');
        } catch (GatewayReleaseException $exception) {
            throw $exception;
        } catch (RequestException $exception) {
            throw $this->failed('Gateway /up did not succeed.', "HTTP {$exception->response->status()}.");
        } catch (Throwable $exception) {
            throw $this->failed('Gateway /up did not succeed.', $exception->getMessage());
        }

        return $result ?? throw $this->failed('Gateway /up did not succeed.', '');
    }

    /** @return array{status: string, version: string} */
    private function check(?string $sha): array
    {
        try {
            $status = $this->http()->get($this->origin.'/api/v1/gateway/status');
        } catch (RequestException $exception) {
            throw $this->failed('Gateway status did not succeed.', "HTTP {$exception->response->status()}.");
        } catch (Throwable $exception) {
            throw $this->failed('Gateway status did not succeed.', $exception->getMessage());
        }

        $this->assertResponse($status, 'Gateway status did not succeed.');

        $decoded = json_decode($status->body(), true);
        $data = is_array($decoded) ? ($decoded['data'] ?? $decoded) : null;
        $reportedStatus = is_array($data) && is_string($data['status'] ?? null) ? $data['status'] : '';
        $version = is_array($data) && is_string($data['version'] ?? null) ? $data['version'] : '';

        if ($reportedStatus !== 'ok' || ($sha !== null && ! $this->versionMatches($sha, $version))) {
            throw new GatewayReleaseException(
                step: 'verify',
                errorCode: 'gateway.release_verify_failed',
                message: "Gateway status is [{$reportedStatus}] at version [{$version}]".($sha === null ? '.' : ", not commit [{$sha}]."),
            );
        }

        return ['status' => $reportedStatus, 'version' => $version];
    }

    private function versionMatches(string $expected, string $observed): bool
    {
        $expected = strtolower($expected);
        $observed = strtolower(trim($observed));

        return GatewayReleaseCommit::isSha($expected)
            && ($observed === $expected || $observed === GatewayReleaseCommit::id($expected));
    }

    private function http(): PendingRequest
    {
        return Http::setHandler(new CurlHandler(['handle_factory' => new OrbitCaCurlFactory]))
            ->connectTimeout(15)
            ->timeout(15)
            ->acceptJson()
            ->withOptions(['verify' => $this->caFile, 'allow_redirects' => false]);
    }

    private function assertResponse(Response $response, string $message): void
    {
        if ($response->failed()) {
            throw $this->failed($message, "HTTP {$response->status()}.");
        }
    }

    private function failed(string $message, string $detail): GatewayReleaseException
    {
        $detail = trim($detail);

        return new GatewayReleaseException(
            step: 'verify',
            errorCode: 'gateway.release_verify_failed',
            message: $detail === '' ? $message : $message.' '.$detail,
        );
    }
}
