<?php

declare(strict_types=1);

namespace App\Domain\Instances\Power;

use App\Models\InstanceRuntime;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * Which development containers the idle sleep acts on (docs/reference/app-dev-runtime-hibernation.md#idle-window-and-sweep): the one
 * setting `ORBIT_HIBERNATION_CONTAINER_SLEEP`, read as `orbit.hibernation.container_sleep`. `false` is `off`, `true` is `all`, and
 * `without_t3` acts only on Instances whose runtime records no T3 server.
 */
enum ContainerSleepScope: string
{
    case Off = 'off';
    case WithoutT3 = 'without_t3';
    case All = 'all';

    /** The value in force. Any other value fails the caller's run with the value named: nothing sleeps by itself then. */
    public static function configured(): self
    {
        $value = Config::get('orbit.hibernation.container_sleep');

        return match ($value) {
            false, null => self::Off,
            true => self::All,
            'without_t3' => self::WithoutT3,
            default => throw new InvalidArgumentException('ORBIT_HIBERNATION_CONTAINER_SLEEP must be false, without_t3 or true, not ['
                .(is_scalar($value) ? (string) $value : get_debug_type($value)).'].'),
        };
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
