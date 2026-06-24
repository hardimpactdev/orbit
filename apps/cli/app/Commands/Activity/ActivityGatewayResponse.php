<?php

declare(strict_types=1);

namespace App\Commands\Activity;

final class ActivityGatewayResponse
{
    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    public static function activitiesFrom(array $response): array
    {
        $data = self::successData($response);

        if (! array_key_exists('activities', $data) || ! is_array($data['activities'])) {
            return [];
        }

        return ActivityGatewayResponseArrays::listOfStringKeyed($data['activities']);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public static function hasNoActivities(array $response): bool
    {
        $data = self::successData($response);

        return array_key_exists('activities', $data) && is_array($data['activities']) && $data['activities'] === [];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>|null
     */
    public static function activityFrom(array $response): ?array
    {
        $data = self::successData($response);

        if (! array_key_exists('activity', $data) || ! is_array($data['activity'])) {
            return null;
        }

        return ActivityGatewayResponseArrays::stringKeyed($data['activity']);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    public static function relatedFrom(array $response): array
    {
        $data = self::successData($response);

        if (! array_key_exists('related', $data) || ! is_array($data['related'])) {
            return [];
        }

        return ActivityGatewayResponseArrays::listOfStringKeyed($data['related']);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private static function successData(array $response): array
    {
        if (! array_key_exists('success', $response) || ! is_array($response['success'])) {
            return [];
        }

        if (! array_key_exists('data', $response['success']) || ! is_array($response['success']['data'])) {
            return [];
        }

        return ActivityGatewayResponseArrays::stringKeyed($response['success']['data']);
    }
}
