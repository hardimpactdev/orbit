<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

/** What a caller should do about a failed GitHub read. */
enum GitHubReadFailureKind: string
{
    /** GitHub limits the token: wait for the reset. */
    case RateLimited = 'rate_limited';

    /** A transport error or a 5xx answer: read again. */
    case Transient = 'transient';

    /** 401, a 403 that is not a rate limit, or another answer that the read does not expect: an operator must look. */
    case Refused = 'refused';

    /** 404: the commit, ref or tree does not exist. Reading again does not help. */
    case NotFound = 'not_found';
}
