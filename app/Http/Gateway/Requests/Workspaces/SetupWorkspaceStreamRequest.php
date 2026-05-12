<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Workspaces;

use App\Http\Gateway\GatewayStreamRequest;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Traits\Body\HasJsonBody;

final class SetupWorkspaceStreamRequest extends GatewayStreamRequest implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $app = null,
        public readonly ?string $path = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/workspaces/setup/stream';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return array_filter([
            'name' => $this->name,
            'app' => $this->app,
            'path' => $this->path,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'text/event-stream',
        ];
    }
}
