<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules\Prose;

/**
 * Classifies a doc into a register profile so prose rules can pick thresholds.
 *
 * Reader-facing prose targets shorter sentences, smaller paragraphs, and second-person
 * voice. Technical contracts and JSON shape docs target normative prose without "you"
 * and accept denser sentences.
 */
enum DocProfile: string
{
    case ReaderFacing = 'reader_facing';
    case Technical = 'technical';

    public static function fromPath(string $relativePath): self
    {
        $needsTechnical = str_contains($relativePath, '/technical/')
            || str_contains($relativePath, '/abstractions/')
            || str_ends_with($relativePath, '/CONCEPTS.md')
            || str_ends_with($relativePath, '/RULES.md');

        return $needsTechnical ? self::Technical : self::ReaderFacing;
    }
}
