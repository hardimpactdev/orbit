<?php

declare(strict_types=1);

namespace App\Librarian;

final class DoctorWarningInspection
{
    /** @var array<string, array<string, DoctorIssue>> */
    public array $doctorIssues = [];

    /**
     * @param  array<string, array{singular: string, doctor_doc: ?string}>  $stateFamilies
     * @param  array<string, array{family?: ?string, kind?: string, allowed_next_commands?: list<string>}>  $warningCodes
     */
    public function __construct(
        public readonly array $stateFamilies,
        public readonly array $warningCodes,
    ) {}
}
