<?php

declare(strict_types=1);

namespace App\Domain\WebSocket;

use App\Models\WebSocketApp;
use Illuminate\Support\Str;

/** Creates a Reverb app with a random app id, key, and secret. */
final readonly class WebSocketAppIssuer
{
    private const int IdentifierLength = 20;

    private const int SecretLength = 40;

    public function issue(string $name): WebSocketApp
    {
        return WebSocketApp::query()->create([
            'name' => $name,
            'app_id' => Str::random(self::IdentifierLength),
            'key' => Str::random(self::IdentifierLength),
            'secret' => Str::random(self::SecretLength),
        ]);
    }
}
