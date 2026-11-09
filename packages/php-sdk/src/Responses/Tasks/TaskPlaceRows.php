<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

/** Shape checks shared by the task place responses. */
final class TaskPlaceRows
{
    /** @return array<string, mixed> */
    public static function row(mixed $value, string $requestId): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw TaskFields::invalid('task place', $requestId);
        }
        $row = [];
        foreach ($value as $key => $item) {
            $row[(string) $key] = $item;
        }

        return $row;
    }

    /** @return list<mixed> */
    public static function list(mixed $value, string $requestId): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw TaskFields::invalid('task place', $requestId);
        }

        return $value;
    }
}
