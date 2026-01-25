<?php

declare(strict_types=1);

namespace App\Services;

use Pusher\Pusher;

/**
 * Broadcasts events to Reverb via the Pusher-compatible API.
 *
 * The CLI always runs on the host and connects to Reverb at 127.0.0.1:8080.
 */
final class ReverbBroadcaster
{
    private ?Pusher $pusher = null;

    private readonly bool $enabled;

    public function __construct(ConfigManager $config)
    {
        $reverbConfig = $config->getReverbConfig();

        // Only enable if configured and Pusher class is available
        $pusherAvailable = class_exists(Pusher::class);
        $this->enabled = $reverbConfig['enabled'] && $pusherAvailable;

        if ($this->enabled) {
            $this->pusher = new Pusher(
                $reverbConfig['app_key'],
                $reverbConfig['app_secret'],
                $reverbConfig['app_id'],
                [
                    'host' => '127.0.0.1',
                    'port' => $reverbConfig['internal_port'] ?? 8080,
                    'scheme' => 'http',
                    'useTLS' => false,
                ]
            );
        }
    }

    /**
     * Broadcast an event to a channel via Reverb Pusher-compatible API.
     */
    public function broadcast(string $channel, string $event, array $data): void
    {
        if (! $this->enabled || ! $this->pusher) {
            return;
        }

        try {
            $this->pusher->trigger($channel, $event, $data);
        } catch (\Throwable) {
            // Silently fail - broadcasting is non-critical for CLI operations
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
