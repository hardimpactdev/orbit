<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use Illuminate\Support\Facades\Validator;

/** The Instance name format shared by admission and advisory reads. */
final readonly class InstanceName
{
    /** @return list<string> */
    public static function rules(): array
    {
        return ['required', 'string', 'max:63', 'regex:/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/'];
    }

    public static function isValid(string $name): bool
    {
        return Validator::make(['name' => $name], ['name' => self::rules()])->passes();
    }
}
