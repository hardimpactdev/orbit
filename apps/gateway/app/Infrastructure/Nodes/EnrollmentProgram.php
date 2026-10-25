<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\RoleName;
use App\Models\Node;
use App\Models\TaskVm;
use Illuminate\Support\Facades\Log;

/**
 * The stable names of the programs {@see NodeEnrollmentPrograms} renders for enrollment and image builds.
 */
final class EnrollmentProgram
{
    public const string Bootstrap = 'bootstrap';

    public const string CaddyPackage = 'caddy-package';

    /** The recipe's pinned JavaScript runtimes of an application role. */
    public const string JavascriptRuntime = 'javascript-runtime';

    public const string VpManager = 'vp-manager';

    public const string ComposerManager = 'composer-manager';

    /**
     * Prefix of the `app-dev` PHP programs: `app-dev-php-source` and one per PHP version, such as `app-dev-php-8-5`.
     * Enrollment does not run them; an Instance's route does, and finds the packages in place.
     */
    public const string AppDevPhp = 'app-dev-php';

    /** Task VM enrollment trusts its image for software only. All per-VM configuration still runs. */
    public static function skipForTaskVm(Node $node, string $program): bool
    {
        if (! $node->exists || ! TaskVm::query()->where('node_id', $node->id)->exists()) {
            return false;
        }

        Log::info('Task VM program skipped: its image ran it.', ['task_vm' => $node->name, 'program' => $program]);

        return true;
    }

    /** The prerequisites program of a role, such as `app-dev-prerequisites`. */
    public static function prerequisites(RoleName $role): string
    {
        return $role->value.'-prerequisites';
    }
}
