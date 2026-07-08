<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\Exceptions\GatewayApiException;
use Illuminate\Console\Command;

/**
 * @mixin Command
 */
trait ResolvesNodeTransportPreference
{
    private const string NODE_TRANSPORT_OPTION = 'node-transport';

    private const array NODE_TRANSPORT_PREFERENCES = [
        'agent-push',
        'auto',
        'transitional-ssh-fallback',
    ];

    protected function nodeTransportPreference(): ?string
    {
        if (! $this->getDefinition()->hasOption(self::NODE_TRANSPORT_OPTION)) {
            return null;
        }

        $preference = $this->option(self::NODE_TRANSPORT_OPTION);

        if (! is_string($preference) || trim($preference) === '') {
            return null;
        }

        $preference = trim($preference);

        if (! in_array($preference, self::NODE_TRANSPORT_PREFERENCES, strict: true)) {
            throw new GatewayApiException(
                "Invalid node transport preference [{$preference}].",
                statusCode: 422,
                body: json_encode([
                    'error' => [
                        'code' => 'validation_failed',
                        'message' => "Invalid node transport preference [{$preference}].",
                        'meta' => [
                            'field' => self::NODE_TRANSPORT_OPTION,
                            'allowed' => self::NODE_TRANSPORT_PREFERENCES,
                        ],
                    ],
                ], JSON_THROW_ON_ERROR),
            );
        }

        return $preference;
    }
}
