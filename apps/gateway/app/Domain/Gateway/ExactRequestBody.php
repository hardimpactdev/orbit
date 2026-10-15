<?php

declare(strict_types=1);

namespace App\Domain\Gateway;

/**
 * A plug-in point: request paths that keep their exact body and bound its size themselves ([Extensions](/reference/extensions#plug-in-points)).
 *
 * The Gateway skips PHP's `post_max_size` check for a matching request, and does not trim its strings or turn empty
 * strings into null, so the body is not parsed before authorization. Contribute a matcher only for routes whose form
 * request or controller enforces its own size limit after authorization: a match removes the Gateway's limit for that
 * path. The match applies whether or not the extension is enabled.
 */
interface ExactRequestBody
{
    /** Whether the raw request path, without a leading slash, keeps its exact body. */
    public function matches(string $path): bool;
}
