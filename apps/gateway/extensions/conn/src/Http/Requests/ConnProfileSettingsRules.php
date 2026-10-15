<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use Closure;
use Illuminate\Validation\Validator;
use Orbit\Extensions\Conn\Domain\ConnProfileSections;

/**
 * The rules for each section of a profile's settings document, shared by the replace and the patch
 * request. A caller passes the dotted path where the section value sits in its request. The keys of the
 * document follow Conn's stores, so they stay camelCase. A map must hold at least one entry: PHP cannot
 * tell `{}` from `[]`, so an empty map would come back as a list. Leave out the key instead.
 */
final class ConnProfileSettingsRules
{
    /** The longest workspace picture Conn keeps (`WORKSPACE_IMAGE_MAX_CHARS`). */
    private const int ImageMaxChars = 200_000;

    private const string ThemeColorKey = '/\A[a-zA-Z][a-zA-Z0-9]{0,63}\z/';

    private const string ThemeColorValue = '/\A[#A-Za-z0-9(),.%\/ -]+\z/';

    private const string DeviceValueKey = '/\A[a-zA-Z][a-zA-Z0-9]{0,63}\z/';

    private const int MaxThemeColors = 80;

    private const int MaxCustomThemes = 50;

    private const int MaxDeviceValues = 128;

    private const int MaxDeviceListItems = 32;

    private const int MaxDeviceStringChars = 512;

    /**
     * The rules for one section value. A null value passes only where `$nullable` says the section can
     * be cleared, and never for `workspaces`.
     *
     * @return array<string, list<mixed>>
     */
    public static function forSection(string $section, string $path, mixed $value, bool $nullable): array
    {
        if ($value === null && $nullable && $section !== ConnProfileSections::Workspaces) {
            return [$path => ['present', 'nullable']];
        }

        return match (true) {
            $section === ConnProfileSections::Workspaces => self::workspaces($path),
            $section === ConnProfileSections::Appearance => self::appearance($path),
            default => self::deviceSection($path),
        };
    }

    /** @return array<string, list<mixed>> */
    public static function workspaces(string $path): array
    {
        return [
            $path => ['present', 'list', 'max:200'],
            "{$path}.*" => ['array:id,name,color,icon,image,projectRefs,projectKeys'],
            "{$path}.*.id" => ['required', 'string', 'max:100', 'distinct'],
            "{$path}.*.name" => ['required', 'string', 'max:100'],
            "{$path}.*.color" => ['required', 'string', 'max:32'],
            "{$path}.*.icon" => ['present', 'nullable', 'string', 'max:64'],
            "{$path}.*.image" => ['sometimes', 'nullable', 'string', 'max:'.self::ImageMaxChars, 'regex:/\Adata:image\/(png|jpeg|webp);base64,[A-Za-z0-9+\/]+=*\z/'],
            "{$path}.*.projectRefs" => ['sometimes', 'list', 'max:500'],
            "{$path}.*.projectRefs.*" => ['string', 'max:512', 'regex:/\A[^:\s]+:\S+\z/'],
            "{$path}.*.projectKeys" => ['sometimes', 'list', 'max:500'],
            "{$path}.*.projectKeys.*" => ['string', 'max:512'],
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function appearance(string $path): array
    {
        $slotKeys = 'id,source,name,appearance,colors';

        return [
            $path => ['array:schema,mode,contrast,themes,customThemes'],
            "{$path}.schema" => ['required', 'integer:strict', 'min:1', 'max:1000'],
            "{$path}.mode" => ['required', 'string', 'in:light,dark,system'],
            // The range of Conn's `appearanceContrast` setting.
            "{$path}.contrast" => ['required', 'integer:strict', 'between:50,200'],
            "{$path}.themes" => ['sometimes', 'array:light,dark', 'min:1'],
            ...self::themeSlot("{$path}.themes.light", $slotKeys),
            ...self::themeSlot("{$path}.themes.dark", $slotKeys),
            "{$path}.customThemes" => ['sometimes', 'list', 'max:'.self::MaxCustomThemes],
            "{$path}.customThemes.*" => ['array:id,name,appearance,colors,variants'],
            "{$path}.customThemes.*.id" => ['required', 'string', 'max:100', 'distinct'],
            "{$path}.customThemes.*.name" => ['required', 'string', 'max:100'],
            "{$path}.customThemes.*.appearance" => ['required', 'string', 'in:light,dark'],
            ...self::colorMap("{$path}.customThemes.*.colors", required: true),
            "{$path}.customThemes.*.variants" => ['sometimes', 'array:light,dark', 'min:1'],
            ...self::colorMap("{$path}.customThemes.*.variants.light"),
            ...self::colorMap("{$path}.customThemes.*.variants.dark"),
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function deviceSection(string $path): array
    {
        return [
            $path => ['array:schema,values'],
            "{$path}.schema" => ['required', 'integer:strict', 'min:1', 'max:1000'],
            "{$path}.values" => ['required', 'array', 'min:1', 'max:'.self::MaxDeviceValues, self::keysMatch(self::DeviceValueKey, 'camelCase names of at most 64 characters')],
            "{$path}.values.*" => [self::deviceValue(...)],
        ];
    }

    /**
     * The `devices` map of a replace: one to eight device types. An empty map is refused, so a client bug
     * cannot remove every device type; a patch removes a type with a null value. The sections inside use
     * {@see self::deviceSection()} at their own path.
     *
     * @return array<string, list<mixed>>
     */
    public static function devices(string $path): array
    {
        return [$path => ['present', 'array', 'min:1', 'max:'.ConnProfileSections::MaxDeviceTypes, self::keysMatch(ConnProfileSections::DeviceTypePattern, 'device types of 2 to 24 lowercase letters, digits, and hyphens')]];
    }

    /**
     * Adds an error for a section that is larger than its limit. Call it after the rules passed.
     */
    public static function checkSize(Validator $validator, string $section, string $path, mixed $value): void
    {
        $limit = match (true) {
            $section === ConnProfileSections::Appearance => ConnProfileSections::MaxAppearanceBytes,
            ConnProfileSections::deviceType($section) !== null => ConnProfileSections::MaxDeviceBytes,
            default => null,
        };

        if ($limit !== null && ConnProfileSections::encodedSize($value) > $limit) {
            $validator->errors()->add($path, 'The section is larger than '.($limit / 1024).' KB.');
        }
    }

    /** @return array<string, list<mixed>> */
    private static function themeSlot(string $path, string $keys): array
    {
        return [
            $path => ['sometimes', "array:{$keys}", 'min:1'],
            "{$path}.id" => ["required_with:{$path}", 'string', 'max:100'],
            "{$path}.source" => ["required_with:{$path}", 'string', 'in:stock,builtin,custom,published'],
            "{$path}.name" => ['sometimes', 'string', 'max:100'],
            "{$path}.appearance" => ['sometimes', 'string', 'in:light,dark'],
            ...self::colorMap("{$path}.colors"),
        ];
    }

    /**
     * A map from color role to a CSS color. The charset keeps out anything that could carry markup or a url.
     *
     * @return array<string, list<mixed>>
     */
    private static function colorMap(string $path, bool $required = false): array
    {
        return [
            $path => [$required ? 'required' : 'sometimes', 'array', 'min:1', 'max:'.self::MaxThemeColors, self::keysMatch(self::ThemeColorKey, 'color roles of at most 64 letters and digits')],
            "{$path}.*" => ['string', 'max:64', 'regex:'.self::ThemeColorValue],
        ];
    }

    /**
     * @return Closure(string, mixed, Closure(string): mixed): void
     */
    private static function keysMatch(string $pattern, string $description): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($pattern, $description): void {
            if (! is_array($value)) {
                return;
            }

            foreach (array_keys($value) as $key) {
                if (! is_string($key) || preg_match($pattern, $key) !== 1) {
                    $fail("The {$attribute} keys must be {$description}.");

                    return;
                }
            }
        };
    }

    /** A device value is a scalar, null, or a short list of scalars, with strings of at most 512 characters. */
    private static function deviceValue(string $attribute, mixed $value, Closure $fail): void
    {
        $scalar = static fn (mixed $item): bool => is_bool($item) || is_int($item) || is_float($item)
            || (is_string($item) && mb_strlen($item) <= self::MaxDeviceStringChars);

        if ($value === null || $scalar($value)) {
            return;
        }

        if (is_array($value) && array_is_list($value) && count($value) <= self::MaxDeviceListItems && array_all($value, $scalar)) {
            return;
        }

        $fail("The {$attribute} value must be a boolean, number, string of at most ".self::MaxDeviceStringChars.' characters, null, or a list of up to '.self::MaxDeviceListItems.' of those scalars.');
    }
}
