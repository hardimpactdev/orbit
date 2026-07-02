<?php

declare(strict_types=1);

namespace App\Enums\Apps;

enum DependencyAuditStatus: string
{
    case Clean = 'clean';
    case Findings = 'findings';
    case NotApplicable = 'not_applicable';
    case Unsupported = 'unsupported';
    case Failed = 'failed';
    case Unknown = 'unknown';
}
