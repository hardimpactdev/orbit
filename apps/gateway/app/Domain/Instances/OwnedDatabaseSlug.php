<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Instance;

/** The connection slug of the database an Instance owns: `<project>-<instance>`, cut to 63 characters with a hash. */
final class OwnedDatabaseSlug
{
    public static function for(Instance $instance): string
    {
        $slug = $instance->project->slug.'-'.$instance->name;

        return strlen($slug) <= 63 ? $slug : rtrim(substr($slug, 0, 54), '-').'-'.substr(sha1($slug), 0, 8);
    }
}
