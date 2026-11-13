<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Instance;

/**
 * The connection slug of a database an Instance owns: `<project>-<instance>`, cut to 63 characters with a hash. A clone's own
 * Valkey user adds the suffix `valkey`, so it never takes the slug of the Instance's SQL database.
 */
final class OwnedDatabaseSlug
{
    public static function for(Instance $instance, ?string $suffix = null): string
    {
        $slug = $instance->project->slug.'-'.$instance->name.($suffix === null ? '' : '-'.$suffix);

        return strlen($slug) <= 63 ? $slug : rtrim(substr($slug, 0, 54), '-').'-'.substr(sha1($slug), 0, 8);
    }
}
