<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Apps;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Apps\AppExecResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class AppExecRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    /**
     * Exactly one of $app or $hostCwd is required. When $app is supplied the
     * request targets the explicit endpoint; when only $hostCwd is supplied
     * the request targets the resolve-by-path endpoint and the gateway
     * resolves the app server-side from the launcher-supplied host cwd.
     *
     * @param  list<string>  $command
     */
    public function __construct(
        public readonly ?string $app,
        public readonly array $command,
        public readonly ?string $hostCwd = null,
    ) {}

    public function resolveEndpoint(): string
    {
        if ($this->app === null) {
            return '/api/apps/exec/by-path';
        }

        return '/api/apps/'.rawurlencode($this->app).'/exec';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        $body = [
            'command' => array_values($this->command),
        ];

        if ($this->hostCwd !== null) {
            $body['host_cwd'] = $this->hostCwd;
        }

        return $body;
    }

    public function createDtoFromResponse(Response $response): AppExecResponse
    {
        return new AppExecResponse(data: $this->unwrapData($response));
    }
}
