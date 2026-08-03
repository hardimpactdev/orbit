<?php

declare(strict_types=1);

namespace App\Enums;

enum DoctorIssueDisposition: string
{
    case GenuineDrift = 'genuine_drift';
    case BlockedInspection = 'blocked_inspection';
    case InvalidIntent = 'invalid_intent';
    case RuntimeIncident = 'runtime_incident';
}
