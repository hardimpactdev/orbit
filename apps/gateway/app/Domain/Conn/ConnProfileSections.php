<?php

declare(strict_types=1);

namespace App\Domain\Conn;

use Illuminate\Validation\ValidationException;

/**
 * The sections of a profile's settings document. Each section has its own version, so two devices that
 * change different sections never conflict. A section is `workspaces`, `appearance`, or `devices.<type>`,
 * where `<type>` names a kind of device such as `desktop` or `iphone`.
 */
final readonly class ConnProfileSections
{
    public const string Workspaces = 'workspaces';

    public const string Appearance = 'appearance';

    private const string DevicePrefix = 'devices.';

    /** A device type is a short lowercase label. It never holds a dot, so a section name stays one path. */
    public const string DeviceTypePattern = '/\A[a-z][a-z0-9-]{1,23}\z/';

    public const int MaxDeviceTypes = 8;

    public const int MaxAppearanceBytes = 512 * 1024;

    public const int MaxDeviceBytes = 64 * 1024;

    public const int MaxDocumentBytes = 4 * 1024 * 1024;

    public static function device(string $type): string
    {
        return self::DevicePrefix.$type;
    }

    /** The device type of a `devices.<type>` section, or null for any other name. */
    public static function deviceType(string $section): ?string
    {
        if (! str_starts_with($section, self::DevicePrefix)) {
            return null;
        }

        $type = substr($section, strlen(self::DevicePrefix));

        return preg_match(self::DeviceTypePattern, $type) === 1 ? $type : null;
    }

    public static function isKnown(string $section): bool
    {
        return $section === self::Workspaces || $section === self::Appearance || self::deviceType($section) !== null;
    }

    /**
     * The document with one section replaced. A null value removes `appearance` or a device section, and
     * `devices` goes away with its last device. `workspaces` always stays a list.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function with(array $settings, string $section, mixed $value): array
    {
        $type = self::deviceType($section);

        if ($type === null) {
            if ($value === null) {
                unset($settings[$section]);
            } else {
                $settings[$section] = $section === self::Workspaces && is_array($value) ? array_values($value) : $value;
            }

            return $settings;
        }

        $devices = isset($settings['devices']) && is_array($settings['devices']) ? $settings['devices'] : [];

        if ($value === null) {
            unset($devices[$type]);
        } else {
            $devices[$type] = $value;
        }

        if ($devices === []) {
            unset($settings['devices']);
        } else {
            $settings['devices'] = $devices;
        }

        return $settings;
    }

    /** The size a value takes in the stored document. */
    public static function encodedSize(mixed $value): int
    {
        return strlen(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '');
    }

    /**
     * Refuses a document that grew past a limit that only the whole document shows.
     *
     * @param  array<string, mixed>  $settings
     *
     * @throws ValidationException
     */
    public static function assertWithinLimits(array $settings): void
    {
        $devices = $settings['devices'] ?? [];

        if (is_array($devices) && count($devices) > self::MaxDeviceTypes) {
            throw ValidationException::withMessages(['settings' => ['A profile holds at most '.self::MaxDeviceTypes.' device types.']]);
        }

        if (self::encodedSize($settings) > self::MaxDocumentBytes) {
            throw ValidationException::withMessages(['settings' => ['The settings document is larger than '.(self::MaxDocumentBytes / 1024 / 1024).' MB.']]);
        }
    }
}
