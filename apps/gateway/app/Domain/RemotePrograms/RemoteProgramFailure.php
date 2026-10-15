<?php

declare(strict_types=1);

namespace App\Domain\RemotePrograms;

/** Why a program run ended without a declared outcome. */
enum RemoteProgramFailure: string
{
    /** The Node has no address to reach it. */
    case Unreachable = 'unreachable';

    /** The program exited with another status than 0, or the connection failed. */
    case NoResult = 'no_result';

    /** Standard output is not one JSON object with a string outcome and an object of data. */
    case InvalidResult = 'invalid_result';

    /** The outcome is not one the program declares. */
    case UndeclaredOutcome = 'undeclared_outcome';

    case TimedOut = 'timed_out';

    case Cancelled = 'cancelled';
}
