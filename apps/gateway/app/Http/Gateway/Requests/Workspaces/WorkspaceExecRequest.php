<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Workspaces;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Workspaces\WorkspaceExecResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class WorkspaceExecRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    /**
     * Exactly one of $workspace or $hostCwd is required. When $workspace is
     * supplied the request targets the explicit endpoint and an optional
     * $app disambiguates collisions. When only $hostCwd is supplied the
     * request targets the resolve-by-path endpoint and the gateway
     * resolves the workspace server-side.
     *
     * @param  list<string>  $command
     */
    public function __construct(
        public readonly ?string $workspace,
        public readonly array $command,
        public readonly ?string $app = null,
        public readonly ?string $hostCwd = null,
    ) {}

    public function resolveEndpoint(): string
    {
        if ($this->workspace === null) {
            return '/api/workspaces/exec/by-path';
        }

        return '/api/workspaces/'.rawurlencode($this->workspace).'/exec';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        if ($this->workspace === null) {
            return [];
        }

        return array_filter([
            'app' => $this->app,
        ], fn (?string $value): bool => $value !== null && $value !== '');
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

    public function createDtoFromResponse(Response $response): WorkspaceExecResponse
    {
        return new WorkspaceExecResponse(data: $this->unwrapData($response));
    }
}
