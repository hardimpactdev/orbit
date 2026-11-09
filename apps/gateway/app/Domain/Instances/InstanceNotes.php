<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Projects\ProjectType;
use RuntimeException;

/**
 * The environment notes of the agent folder (docs/reference/development-containers.md#environment-notes): one note per kind
 * of Project, facts about the container only. The create flow links an Instance's agent settings to the note of its type.
 */
final readonly class InstanceNotes
{
    public static function for(ProjectType $type): string
    {
        return match ($type) {
            ProjectType::LaravelApp, ProjectType::SymfonyApp => 'web-app',
            ProjectType::Monorepo => 'monorepo',
            ProjectType::LaravelPackage, ProjectType::NodePackage => 'package',
        };
    }

    /** @return array<string, string> Note name => Markdown, in name order. */
    public static function all(): array
    {
        $notes = [];
        foreach (['monorepo', 'package', 'web-app'] as $name) {
            $content = file_get_contents(resource_path("instances/notes/{$name}.md"));
            $notes[$name] = is_string($content) ? $content : throw new RuntimeException("The environment note [{$name}] is missing.");
        }

        return $notes;
    }
}
