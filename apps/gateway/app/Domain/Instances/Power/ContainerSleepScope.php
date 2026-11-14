<?php

declare(strict_types=1);

namespace App\Domain\Instances\Power;

use App\Models\InstanceRuntime;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * Which development containers the idle sleep acts on (docs/reference/app-dev-runtime-hibernation.md#idle-window-and-sweep): the one
 * setting `ORBIT_HIBERNATION_CONTAINER_SLEEP`, read as `orbit.hibernation.container_sleep`. `false` is `off`, `true` is `all`, and
 * `without_t3` acts only on Instances whose runtime records no T3 server. Any other value counts as `false`.
 */
enum ContainerSleepScope: string
{
    case Off = 'off';
    case WithoutT3 = 'without_t3';
    case All = 'all';

    /**
     * The value in force. Any other value counts as `off`, so nothing sleeps by itself; the first read of it logs one error that names
     * it (once per value, not at every sweep), and the dry run says so each time.
     */
    public static function configured(): self
    {
        $value = Config::get('orbit.hibernation.container_sleep');
        $invalid = self::invalid();
        if ($invalid !== null && Cache::add('orbit.hibernation.container_sleep.invalid:'.sha1($invalid), true)) {
            Log::error("ORBIT_HIBERNATION_CONTAINER_SLEEP is [{$invalid}], not false, without_t3 or true: it counts as false, so no container sleeps by itself.");
        }

        return match ($value) {
            true => self::All,
            'without_t3' => self::WithoutT3,
            default => self::Off,
        };
    }

    /** The configured value as written when it is none of false, without_t3 or true; null when it is valid. */
    public static function invalid(): ?string
    {
        $value = Config::get('orbit.hibernation.container_sleep');

        return in_array($value, [false, null, true, 'without_t3'], true) ? null : (is_scalar($value) ? (string) $value : get_debug_type($value));
    }

    /** The setting's own spelling of the value, as the operator writes it. */
    public function setting(): string
    {
        return match ($this) {
            self::Off => 'false',
            self::WithoutT3 => 'without_t3',
            self::All => 'true',
        };
    }

    /** The reason this value keeps a development container awake, or null when the value acts on it. */
    public function excludes(InstanceRuntime $runtime): ?string
    {
        return match (true) {
            $this === self::Off => 'off',
            $this === self::WithoutT3 && $runtime->t3_port !== null => 't3_server',
            default => null,
        };
    }
}
