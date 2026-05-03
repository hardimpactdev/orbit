<?php

declare(strict_types=1);

namespace App\Services\Gateway;

final class GatewayRequestSender
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
}
