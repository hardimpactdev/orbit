<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\RoleName;

/**
 * Stable prerequisite names used by enrollment and image state.
 */
final class EnrollmentProgram
{
    public const string Bootstrap = 'bootstrap';

    public const string CaddyPackage = 'caddy-package';

    /** The recipe's pinned JavaScript runtimes of an application role. */
    public const string JavascriptRuntime = 'javascript-runtime';

    /**
     * Prefix of the `app-dev` PHP programs: `app-dev-php-source` and one per PHP version, such as `app-dev-php-8-5`.
     * Enrollment does not run them; an Instance's route does, and finds the packages in place.
     */
    public const string AppDevPhp = 'app-dev-php';

    /** The prerequisites program of a role, such as `app-dev-prerequisites`. */
    public static function prerequisites(RoleName $role): string
    {
        return $role->value.'-prerequisites';
    }
}
