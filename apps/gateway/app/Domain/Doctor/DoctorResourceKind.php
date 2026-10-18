<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

enum DoctorResourceKind
{
    case Rows;
    case Inventory;
    case Environments;
    case Attachments;
    case Connections;
}
