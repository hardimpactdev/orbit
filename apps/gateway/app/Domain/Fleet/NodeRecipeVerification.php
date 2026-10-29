<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Models\Node;

/** A historical success does not prove the Node after a later mutation attempt. */
final class NodeRecipeVerification
{
    public static function verified(Node $node): bool
    {
        $last = $node->settings['machine_recipe'] ?? null;
        $attempt = $node->settings['machine_recipe_attempt'] ?? null;

        return is_array($last) && (isset($last['version']) || isset($last['partial_version']))
            && ($attempt === null || (is_array($attempt) && ($attempt['status'] ?? null) === 'succeeded'));
    }
}
