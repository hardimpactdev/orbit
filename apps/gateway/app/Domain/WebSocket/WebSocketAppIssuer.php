<?php

declare(strict_types=1);

namespace App\Domain\WebSocket;

use App\Domain\Shared\ResourceOperationException;
use App\Models\WebSocketApp;
use Illuminate\Support\Str;

/** Creates a Reverb app with a random app id, key, and secret. */
final readonly class WebSocketAppIssuer
{
    private const int IdentifierLength = 20;

    private const int SecretLength = 40;

    public function __construct(private WebSocketRegistry $registry = new WebSocketRegistry) {}

    public function issue(string $name): WebSocketApp
    {
        return $this->registry->write(function () use ($name): WebSocketApp {
            if (WebSocketApp::query()->count() >= 10000) {
                throw new ResourceOperationException('websocket.app_limit', 'The registry supports at most 10000 apps.', 409);
            }
            $app = WebSocketApp::query()->create([
                'name' => $name,
                'app_id' => Str::random(self::IdentifierLength),
                'key' => Str::random(self::IdentifierLength),
                'secret' => Str::random(self::SecretLength),
            ]);
            $this->registry->advance();

            return $app;
        });
    }
}
