<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Tools;

use App\Http\Gateway\GatewayStreamRequest;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Traits\Body\HasJsonBody;

final class ToolActionStreamRequest extends GatewayStreamRequest implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $action,
        public readonly ?string $tool = null,
        public readonly array $payload = [],
    ) {
        if ($this->action === 'remove') {
            $this->method = Method::DELETE;
        }
    }

    public function resolveEndpoint(): string
    {
        if ($this->action === 'update-all') {
            return '/api/tools/update';
        }

        $tool = rawurlencode((string) $this->tool);

        if ($this->action === 'remove') {
            return "/api/tools/{$tool}";
        }

        return "/api/tools/{$tool}/{$this->action}";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        if ($this->action === 'remove') {
            return array_filter([
                ...$this->payload,
                'destructive_consent' => true,
            ], static fn (mixed $value): bool => $value !== null);
        }

        return array_filter($this->payload, static fn (mixed $value): bool => $value !== null);
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
