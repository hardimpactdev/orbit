<?php

declare(strict_types=1);

namespace App\Services\Gateway;

final readonly class GatewayRequestSender
{
    public function __construct(
        private GatewayResponseParser $parser,
    ) {}

    public static function make(): static
    {
        return app(self::class);
    }

    public function get(string $path, array $query = []): GatewayResponse
    {
        $response = GatewayClient::make()->get($path, $query);

        return $this->parser->parse($response);
    }

    public function post(string $path, array $data = []): GatewayResponse
    {
        $response = GatewayClient::make()->post($path, $data);

        return $this->parser->parse($response);
    }

    public function put(string $path, array $data = []): GatewayResponse
    {
        $response = GatewayClient::make()->put($path, $data);

        return $this->parser->parse($response);
    }

    public function delete(string $path, array $data = []): GatewayResponse
    {
        $response = GatewayClient::make()->delete($path, $data);

        return $this->parser->parse($response);
    }

    public function send(GatewayRequest $request): GatewayResponse
    {
        return match ($request->method()) {
            'GET' => $this->get($request->path(), $request->query()),
            'POST' => $this->post($request->path(), $request->data()),
            'PUT' => $this->put($request->path(), $request->data()),
            'DELETE' => $this->delete($request->path(), $request->data()),
            default => throw new \InvalidArgumentException("Unsupported HTTP method: {$request->method()}"),
        };
    }
}
