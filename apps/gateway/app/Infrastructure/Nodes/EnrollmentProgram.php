<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\RoleName;

/**
 * The stable names of the programs {@see NodeEnrollmentPrograms} renders. Enrollment, the task VM image manifest,
 * and the E2E role images all name programs with these.
 */
final class EnrollmentProgram
{
    public const string Bootstrap = 'bootstrap';

    public const string CaddyPackage = 'caddy-package';

    public const string VpManager = 'vp-manager';

    public const string ComposerManager = 'composer-manager';

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
