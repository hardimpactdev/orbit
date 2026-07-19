<?php

declare(strict_types=1);

namespace App\Services\Activity;

use Spatie\Activitylog\Models\Activity;

final class ActivityPayloadCompatibility
{
    /**
     * @var list<string>
     */
    private const array EFFECTS = [
        'read',
        'write',
        'destructive',
    ];

    /**
     * @param  array<string, mixed>  $properties
     * @return array{effect: string|null, channel: string, properties: array<string, mixed>}
     */
    public static function normalize(Activity $activity, array $properties): array
    {
        $storedEffect = $activity->properties?->get('type');
        $isLegacyHostKeyActivity =
            $activity->log_name === 'security'
            && is_string($activity->event)
            && str_starts_with($activity->event, 'node.host_key.');

        $effect = is_string($storedEffect) && in_array($storedEffect, self::EFFECTS, true)
            ? $storedEffect
            : ($isLegacyHostKeyActivity ? 'write' : null);
        $channel = $isLegacyHostKeyActivity
            ? 'api'
            : self::storedChannel($activity);

        if (
            $isLegacyHostKeyActivity
            && is_string($storedEffect)
            && ! in_array($storedEffect, self::EFFECTS, true)
            && ! array_key_exists('host_key_type', $properties)
        ) {
            $properties['host_key_type'] = $storedEffect;
        }

        return [
            'effect' => $effect,
            'channel' => $channel,
            'properties' => $properties,
        ];
    }

    private static function storedChannel(Activity $activity): string
    {
        return is_string($activity->log_name) && $activity->log_name !== ''
            ? $activity->log_name
            : 'default';
    }
}
