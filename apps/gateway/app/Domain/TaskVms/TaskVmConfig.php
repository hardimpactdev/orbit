<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\WireGuard\Ipv4Subnet;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ValidatedInput;
use InvalidArgumentException;

/**
 * The validation that {@see TaskVmSettings}, {@see TaskVmHost::fromConfig()}, and {@see TaskVmUpCloud::fromConfig()}
 * share. Each failure is a `task_vm.invalid_config` error that names the key and what it must be.
 */
final class TaskVmConfig
{
    private const array PrivateRanges = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];

    /**
     * Validates `$data` with Laravel's validator and throws the first failure. `$messages` maps an attribute, or an
     * attribute and a rule, to the whole message.
     *
     * @param  array<array-key, mixed>  $data
     * @param  array<string, list<mixed>>  $rules
     * @param  array<string, string>  $messages
     *
     * @throws TaskVmException
     */
    public static function validate(array $data, array $rules, array $messages): ValidatedInput
    {
        $validator = Validator::make($data, $rules, $messages);
        if ($validator->fails()) {
            throw self::invalid((string) $validator->errors()->first());
        }

        return $validator->safe();
    }

    /**
     * The strings of a validated list, in order.
     *
     * @return list<string>
     */
    public static function strings(mixed $list): array
    {
        return array_values(array_filter(is_array($list) ? $list : [], is_string(...)));
    }

    /**
     * The integers of a validated list, in order.
     *
     * @return list<int>
     */
    public static function ints(mixed $list): array
    {
        return array_values(array_filter(is_array($list) ? $list : [], is_int(...)));
    }

    public static function invalid(string $reason): TaskVmException
    {
        return new TaskVmException(TaskVmError::InvalidConfig, "The task VM config is invalid: {$reason}", 500);
    }

    /** An unset or empty value is absent. */
    public static function filled(mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }

    /**
     * The reserved WireGuard range: a private IPv4 network.
     *
     * @throws TaskVmException
     */
    public static function wireguardRange(mixed $value): Ipv4Subnet
    {
        try {
            $range = Ipv4Subnet::from(is_string($value) ? $value : '');
        } catch (InvalidArgumentException) {
            throw self::invalid('wireguard_range must be an IPv4 network, for example 10.44.0.128/25.');
        }
        if (! self::isPrivate($range)) {
            throw self::invalid('wireguard_range must be a private IPv4 network.');
        }

        return $range;
    }

    /**
     * A rule for a host bridge: a private IPv4 network from /16 to /28 that does not overlap the VPN subnet. `$key`
     * names the value in the messages.
     *
     * @return Closure(string, mixed, Closure(string): mixed): void
     */
    public static function bridgeRule(string $key, Ipv4Subnet $vpnSubnet): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($key, $vpnSubnet): void {
            try {
                $subnet = Ipv4Subnet::from(is_string($value) ? $value : '');
            } catch (InvalidArgumentException) {
                $fail("{$key} must be an IPv4 network, for example 10.251.77.0/24.");

                return;
            }
            if ($subnet->prefixLength() < 16 || $subnet->prefixLength() > 28 || ! self::isPrivate($subnet)) {
                $fail("{$key} must be a private IPv4 network from /16 to /28.");
            } elseif ($subnet->contains($vpnSubnet->networkAddress()) || $vpnSubnet->contains($subnet->networkAddress())) {
                $fail("{$key} must not overlap the VPN subnet [{$vpnSubnet->value()}].");
            }
        };
    }

    private static function isPrivate(Ipv4Subnet $subnet): bool
    {
        [$first, $last] = [$subnet->networkAddress(), $subnet->usableRange()[1]];
        foreach (self::PrivateRanges as $range) {
            $private = Ipv4Subnet::from($range);
            if ($private->contains($first) && $private->contains($last)) {
                return true;
            }
        }

        return false;
    }
}
