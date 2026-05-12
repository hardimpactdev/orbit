<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Workspaces;

use App\Http\Gateway\GatewayStreamRequest;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Traits\Body\HasJsonBody;

final class CreateWorkspaceStreamRequest extends GatewayStreamRequest implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $name,
        public readonly string $app,
        public readonly string $base,
        public readonly ?string $phpVersion = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/workspaces/stream';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return array_filter([
            'name' => $this->name,
            'app' => $this->app,
            'base' => $this->base,
            'php_version' => $this->phpVersion,
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
